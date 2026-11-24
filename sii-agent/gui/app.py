from __future__ import annotations
import json, os, queue, subprocess, sys, threading, time, logging
from logging.handlers import RotatingFileHandler
from pathlib import Path
import tkinter as tk
from tkinter import ttk, messagebox

APP = 'Agente SII · qlc.cl'
FROZEN = bool(getattr(sys, 'frozen', False))
BASE = Path(sys.executable).resolve().parent if FROZEN else Path(__file__).resolve().parents[1]
NODE = BASE / 'node' / 'node.exe'
WORKER = BASE / 'sii-agent' / 'src' / 'index.js'
BROWSERS = BASE / 'browsers'
LOG_DIR = BASE / 'logs'
LOG_DIR.mkdir(exist_ok=True)
logging.basicConfig(level=logging.INFO, handlers=[RotatingFileHandler(LOG_DIR / 'agente.log', maxBytes=1_000_000, backupCount=5, encoding='utf-8')], format='%(asctime)s %(message)s')

NAVY='#10243d'; NAVY2='#183a5b'; BG='#f3f6fa'; WHITE='#ffffff'; TEXT='#1d2b3a'; MUTED='#607286'; TEAL='#087f8c'; RED='#b42318'; GREEN='#11734b'; BORDER='#d6e0ea'

class AgenteApp:
    def __init__(self, root):
        self.root=root; self.proc=None; self.events=queue.Queue(); self.rows={}; self.closing=False; self.restart_after=False; self.show_browser=tk.BooleanVar(value=True)
        root.title(APP); root.geometry('1120x760'); root.minsize(900,600); root.configure(bg=BG)
        self._style(); self._layout(); self._start_worker(); self.root.protocol('WM_DELETE_WINDOW', self.close); self.root.after(150,self._drain); self.root.after(5000,self._poll_status)

    def _style(self):
        s=ttk.Style(); s.theme_use('clam'); s.configure('Treeview', background=WHITE, fieldbackground=WHITE, foreground=TEXT, rowheight=31, bordercolor=BORDER, font=('Segoe UI',9)); s.configure('Treeview.Heading', background='#eaf0f6', foreground=NAVY, font=('Segoe UI Semibold',9), relief='flat'); s.map('Treeview',background=[('selected','#d9e9f7')],foreground=[('selected',NAVY)])
        s.configure('TButton',font=('Segoe UI Semibold',9),padding=(12,8)); s.configure('Primary.TButton',background=TEAL,foreground=WHITE); s.map('Primary.TButton',background=[('active','#066b76')]); s.configure('Danger.TButton',background='#fff0ef',foreground=RED); s.configure('TCheckbutton',background=BG,foreground=TEXT,font=('Segoe UI',9)); s.configure('TNotebook',background=BG,borderwidth=0); s.configure('TNotebook.Tab',padding=(14,9),font=('Segoe UI Semibold',9))

    def _layout(self):
        top=tk.Frame(self.root,bg=NAVY,height=88); top.pack(fill='x'); top.pack_propagate(False)
        tk.Label(top,text='Agente SII',font=('Segoe UI Semibold',20),bg=NAVY,fg=WHITE).pack(anchor='w',padx=24,pady=(13,0))
        tk.Label(top,text='Cola central de sincronización · qlc.cl',font=('Segoe UI',9),bg=NAVY,fg='#c3d4e6').pack(anchor='w',padx=25)
        status=tk.Frame(self.root,bg=WHITE,highlightbackground=BORDER,highlightthickness=1); status.pack(fill='x',padx=20,pady=(18,12))
        self.dot=tk.Label(status,text='●',font=('Segoe UI',15),fg='#a0adba',bg=WHITE); self.dot.pack(side='left',padx=(16,8),pady=13)
        self.status_text=tk.Label(status,text='Conectando…',font=('Segoe UI Semibold',10),fg=TEXT,bg=WHITE); self.status_text.pack(side='left')
        self.browser_text=tk.Label(status,text='· Chromium iniciando…',font=('Segoe UI',9),fg=MUTED,bg=WHITE); self.browser_text.pack(side='left',padx=(12,0))
        self.active_text=tk.Label(status,text='Sin tarea activa',font=('Segoe UI',9),fg=MUTED,bg=WHITE); self.active_text.pack(side='left',padx=24)
        controls=tk.Frame(status,bg=WHITE); controls.pack(side='right',padx=12)
        ttk.Checkbutton(controls,text='Mostrar navegador',variable=self.show_browser,command=self._browser_toggle).pack(side='left',padx=8)
        ttk.Button(controls,text='Cancelar tarea',style='Danger.TButton',command=self.cancel).pack(side='left',padx=4)
        ttk.Button(controls,text='↻ Reiniciar',style='Primary.TButton',command=self.restart).pack(side='left',padx=4)
        self.tabs=ttk.Notebook(self.root); self.tabs.pack(fill='both',expand=True,padx=20,pady=(0,18))
        queue_tab=tk.Frame(self.tabs,bg=BG); logs_tab=tk.Frame(self.tabs,bg=BG); self.tabs.add(queue_tab,text='Cola de trabajos'); self.tabs.add(logs_tab,text='Registros')
        tk.Label(queue_tab,text='Tareas solicitadas',font=('Segoe UI Semibold',13),fg=NAVY,bg=BG).pack(anchor='w',pady=(8,3))
        tk.Label(queue_tab,text='Se procesa una tarea a la vez. Los datos aquí son resúmenes; nunca se muestran claves ni tokens.',font=('Segoe UI',9),fg=MUTED,bg=BG).pack(anchor='w',pady=(0,12))
        frame=tk.Frame(queue_tab,bg=WHITE,highlightbackground=BORDER,highlightthickness=1); frame.pack(fill='both',expand=True)
        cols=('id','empresa','tipo','periodo','libro','estado','solicitante','mensaje')
        self.tree=ttk.Treeview(frame,columns=cols,show='headings',selectmode='browse')
        widths={'id':56,'empresa':190,'tipo':105,'periodo':80,'libro':85,'estado':100,'solicitante':135,'mensaje':310}
        headings={'id':'ID','empresa':'Empresa','tipo':'Tipo','periodo':'Período','libro':'Libro','estado':'Estado','solicitante':'Solicitante','mensaje':'Último mensaje'}
        for c in cols: self.tree.heading(c,text=headings[c]); self.tree.column(c,width=widths[c],minwidth=50,stretch=(c in ('empresa','mensaje')))
        y=ttk.Scrollbar(frame,orient='vertical',command=self.tree.yview); x=ttk.Scrollbar(frame,orient='horizontal',command=self.tree.xview); self.tree.configure(yscrollcommand=y.set,xscrollcommand=x.set); self.tree.pack(side='top',fill='both',expand=True); y.pack(side='right',fill='y'); x.pack(side='bottom',fill='x')
        self.tree.tag_configure('active',background='#e7f4fa'); self.tree.tag_configure('pending',background='#fff9e8'); self.tree.bind('<<TreeviewSelect>>',lambda _e:self._selection())
        foot=tk.Frame(queue_tab,bg=BG); foot.pack(fill='x',pady=(9,0)); self.count=tk.Label(foot,text='Esperando estado de cola…',font=('Segoe UI',9),fg=MUTED,bg=BG); self.count.pack(side='left'); ttk.Button(foot,text='Actualizar',command=lambda:self._send({'accion':'estado'})).pack(side='right')
        tk.Label(logs_tab,text='Actividad reciente',font=('Segoe UI Semibold',13),fg=NAVY,bg=BG).pack(anchor='w',pady=(8,10))
        box=tk.Frame(logs_tab,bg=WHITE,highlightbackground=BORDER,highlightthickness=1); box.pack(fill='both',expand=True)
        self.log_text=tk.Text(box,bg='#101d2b',fg='#d4e3ef',insertbackground=WHITE,font=('Cascadia Mono',9),wrap='word',relief='flat',state='disabled'); sy=ttk.Scrollbar(box,command=self.log_text.yview); self.log_text.configure(yscrollcommand=sy.set); self.log_text.pack(side='left',fill='both',expand=True,padx=1,pady=1); sy.pack(side='right',fill='y')
        tk.Label(logs_tab,text=f'Los registros rotan automáticamente en {LOG_DIR}',font=('Segoe UI',9),fg=MUTED,bg=BG).pack(anchor='w',pady=8)
        self._append_log('Interfaz iniciada. Conectando con el agente central…')

    def _send(self,data):
        if self.proc and self.proc.poll() is None:
            try: self.proc.stdin.write(json.dumps(data,ensure_ascii=False)+'\n'); self.proc.stdin.flush()
            except (BrokenPipeError,OSError): pass

    def _start_worker(self):
        if self.closing:return
        if not NODE.exists() or not WORKER.exists():
            messagebox.showerror(APP,'No se encontró Node o el worker dentro del paquete portable.'); return
        env=os.environ.copy(); env['SII_URL_SERVIDOR']=env.get('SII_URL_SERVIDOR','https://qlc.cl'); env['PLAYWRIGHT_BROWSERS_PATH']=str(BROWSERS); env['SII_HEADLESS']='false' if self.show_browser.get() else 'true'; env['SII_INTERVALO_SEG']='15'
        flags=getattr(subprocess,'CREATE_NO_WINDOW',0)
        self.proc=subprocess.Popen([str(NODE),str(WORKER),'--gui'],cwd=str(BASE/'sii-agent'),stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True,encoding='utf-8',errors='replace',bufsize=1,env=env,creationflags=flags)
        threading.Thread(target=self._reader,args=(self.proc,),daemon=True).start(); self._append_log('Worker iniciado.')

    def _reader(self,proc):
        for line in proc.stdout:
            line=line.rstrip('\r\n')
            if line.startswith('@@AGENTE_GUI@@'):
                try:self.events.put(json.loads(line[len('@@AGENTE_GUI@@'):]))
                except json.JSONDecodeError:pass
            elif line:self.events.put({'tipo':'log','mensaje':line})
        self.events.put({'tipo':'salida','codigo':proc.wait()})

    def _drain(self):
        try:
            while True:
                e=self.events.get_nowait(); kind=e.get('tipo')
                if kind=='log':self._append_log(e.get('mensaje',''))
                elif kind=='estado':self._render_state(e)
                elif kind=='salida':
                    self._append_log(f"Worker finalizado (código {e.get('codigo')}).")
                    if self.restart_after and not self.closing:
                        self.restart_after=False; self.root.after(900,self._start_worker)
                    elif not self.closing:self.status_text.config(text='Agente detenido'); self.dot.config(fg='#a0adba')
        except queue.Empty:pass
        if not self.closing:self.root.after(150,self._drain)

    def _render_state(self,data):
        agent=data.get('agente') or {}; connected=agent.get('conectado',False)
        self.dot.config(fg=GREEN if connected else '#b54708'); self.status_text.config(text=('Conectado' if connected else 'Desconectado')+(' · '+str(agent.get('nombre')) if agent.get('nombre') else ''))
        browser=data.get('navegador') or {}
        self.browser_text.config(text=('· Chromium abierto · visible' if browser.get('activo') and browser.get('visible') else ('· Chromium abierto · segundo plano' if browser.get('activo') else '· Chromium iniciando…')))
        active=data.get('activo'); self.active_text.config(text=(f"Procesando tarea #{active['id']} · {active.get('tipo','')} {active.get('periodo','')} {active.get('libro','')}" if active else 'Sin tarea activa'))
        tasks=data.get('tareas') or []; current=set()
        for t in tasks:
            iid=str(t['id']); current.add(iid); vals=(iid,t.get('empresa',''),t.get('tipo',''),t.get('periodo',''),t.get('libro',''),t.get('estado',''),t.get('solicitante',''),t.get('mensaje',''))
            tag='active' if t.get('estado') in ('en_proceso','cancelando','reencolando') else ('pending' if t.get('estado')=='pendiente' else '')
            if self.tree.exists(iid):self.tree.item(iid,values=vals,tags=(tag,) if tag else ())
            else:self.tree.insert('', 'end',iid=iid,values=vals,tags=(tag,) if tag else ())
        for iid in list(self.tree.get_children()):
            if iid not in current:self.tree.delete(iid)
        pending=sum(1 for t in tasks if t.get('estado')=='pendiente'); self.count.config(text=f'{pending} pendiente(s) · {len(tasks)-pending} recientes · actualizado {time.strftime("%H:%M:%S")}')
        self._selection()

    def _selection(self):
        # Las acciones se validan en servidor al ejecutarse; una tarea ya terminada será rechazada.
        pass
    def _append_log(self,line):
        safe=str(line).replace('\x1b','')
        import re
        safe=re.sub(r'(?i)(authorization|token|clave|password|cookie)(\s*[:=]\s*)(?:Bearer\s+)?[^\s,;]+',r'\1\2[oculto]',safe)
        logging.info(safe)
        self.log_text.configure(state='normal'); self.log_text.insert('end',safe+'\n'); self.log_text.see('end')
        lines=int(self.log_text.index('end-1c').split('.')[0])
        if lines>1200:self.log_text.delete('1.0',f'{lines-1000}.0')
        self.log_text.configure(state='disabled')

    def _poll_status(self):
        self._send({'accion':'estado'})
        if not self.closing:self.root.after(5000,self._poll_status)
    def _browser_toggle(self):
        self._send({'accion':'mostrar_navegador','valor':self.show_browser.get()})
        self._append_log('La visibilidad de Chromium se aplicará al terminar la tarea activa.' if self.active_text.cget('text').startswith('Procesando') else 'Actualizando la visibilidad de Chromium…')
    def cancel(self):
        sel=self.tree.selection()
        active=self.active_text.cget('text')
        if sel:
            self._send({'accion':'cancelar','tarea_id':int(sel[0])}); self._append_log(f'Solicitando cancelación de la tarea #{sel[0]}…')
        elif active.startswith('Procesando tarea #'):
            self._send({'accion':'cancelar'}); self._append_log('Solicitando cancelación de la tarea activa…')
        else:messagebox.showinfo(APP,'Selecciona una tarea o espera a que comience una sincronización.')
    def restart(self):
        if not messagebox.askyesno(APP,'El agente se reiniciará. Si hay una tarea activa, volverá a la cola. ¿Continuar?'):return
        self.restart_after=True; self._send({'accion':'reiniciar'}); self._append_log('Reinicio solicitado; la tarea activa se devolverá a la cola.')
    def close(self):
        self.closing=True; self._append_log('Cerrando agente…'); self._send({'accion':'salir'})
        def wait_exit():
            if self.proc and self.proc.poll() is None and time.monotonic()<self._deadline:return self.root.after(200,wait_exit)
            if self.proc and self.proc.poll() is None:self.proc.terminate()
            self.root.destroy()
        self._deadline=time.monotonic()+15; self.root.after(100,wait_exit)

if __name__=='__main__':
    root=tk.Tk(); app=AgenteApp(root); root.mainloop()
