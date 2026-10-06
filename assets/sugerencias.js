'use strict';
/* Pantalla de sugerencias: envía reportes con adjuntos a api/sugerencias.php y lista los propios
   (o todos, con estado y nota editables, si el usuario es administrador). */
const SUG_API='../api/sugerencias.php';
const SUG_MAX_ARCHIVOS=5,SUG_MAX_BYTES=10*1024*1024;
const SUG_EXT=['png','jpg','jpeg','gif','webp','pdf','txt','csv','xls','xlsx','docx'];
const SUG_ESTADOS={nueva:'Nueva',en_revision:'En revisión',resuelta:'Resuelta'};
const sug={archivos:[],estado:'',esAdmin:false};
const $=id=>document.getElementById(id);

function el(tag,props={},...hijos){const e=document.createElement(tag);for(const [k,v] of Object.entries(props)){if(k==='class')e.className=v;else if(k==='text')e.textContent=v;else e.setAttribute(k,v);}e.append(...hijos.filter(h=>h!=null));return e;}
function mensaje(texto,ok=false){const m=$('sug-mensaje');m.textContent=texto;m.classList.toggle('ok',ok);m.hidden=!texto;}
function tamano(b){return b>=1048576?(b/1048576).toFixed(1)+' MB':Math.max(1,Math.round(b/1024))+' KB';}

function agregarArchivos(lista){
 for(const f of lista){
  const ext=(f.name.split('.').pop()||'').toLowerCase();
  if(!SUG_EXT.includes(ext)){mensaje('«'+f.name+'»: tipo de archivo no permitido.');continue;}
  if(f.size>SUG_MAX_BYTES){mensaje('«'+f.name+'» pesa más de 10 MB.');continue;}
  if(sug.archivos.length>=SUG_MAX_ARCHIVOS){mensaje('Puedes adjuntar hasta 5 archivos.');break;}
  sug.archivos.push(f);
 }
 pintarArchivos();
}
function pintarArchivos(){
 const ul=$('sug-lista-archivos');ul.replaceChildren();
 sug.archivos.forEach((f,i)=>{const quitar=el('button',{type:'button',text:'Quitar'});quitar.addEventListener('click',()=>{sug.archivos.splice(i,1);pintarArchivos();});ul.append(el('li',{},el('span',{text:f.name+' · '+tamano(f.size)}),quitar));});
}

async function enviar(event){
 event.preventDefault();mensaje('');
 const form=$('sug-form'),comentario=$('sug-comentario').value.trim();
 if(!comentario){mensaje('Escribe un comentario.');$('sug-comentario').focus();return;}
 const datos=new FormData();
 datos.append('tipo',form.querySelector('input[name=tipo]:checked').value);
 datos.append('pagina',$('sug-pagina').value.trim());
 datos.append('comentario',comentario);
 sug.archivos.forEach(f=>datos.append('adjuntos[]',f,f.name));
 const boton=$('sug-enviar');boton.disabled=true;boton.textContent='Enviando…';
 try{
  const r=await fetch(SUG_API,{method:'POST',body:datos,headers:{'X-Icontador':'1',Accept:'application/json'},credentials:'same-origin'});
  const j=await r.json().catch(()=>({}));
  if(!r.ok)throw new Error(j.error||'No se pudo enviar el reporte.');
  form.reset();sug.archivos=[];pintarArchivos();$('sug-pagina').value=origen();
  mensaje('¡Gracias! Tu reporte quedó registrado con el número '+j.id+'.',true);
  cargar();
 }catch(e){mensaje(e.message);}
 finally{boton.disabled=false;boton.textContent='Enviar';}
}

async function cargar(){
 const lista=$('sug-lista');
 try{
  const r=await fetch(SUG_API+(sug.estado?'?estado='+encodeURIComponent(sug.estado):''),{headers:{Accept:'application/json'},credentials:'same-origin'});
  const j=await r.json().catch(()=>({}));
  if(!r.ok)throw new Error(j.error||'No se pudieron cargar los reportes.');
  sug.esAdmin=!!j.usuario?.es_admin;
  $('sug-lista-titulo').textContent=sug.esAdmin?'Reportes recibidos':'Mis reportes';
  $('sug-filtros').hidden=!sug.esAdmin;
  lista.replaceChildren();
  if(!j.sugerencias.length){lista.append(el('p',{class:'sug-ayuda',text:sug.estado?'No hay reportes con este estado.':'Aún no hay reportes.'}));return;}
  j.sugerencias.forEach(s=>lista.append(item(s)));
 }catch(e){lista.replaceChildren(el('p',{class:'sug-mensaje',text:e.message}));}
}

function item(s){
 const meta=el('div',{class:'sug-meta'},el('strong',{text:'#'+s.id}),el('span',{class:'sug-chip '+s.tipo,text:s.tipo==='problema'?'Problema':'Sugerencia'}),
  el('span',{class:'sug-chip '+s.estado,text:SUG_ESTADOS[s.estado]||s.estado}),el('span',{text:s.creado_en}),
  sug.esAdmin?el('span',{text:(s.usuario_nombre||'')+(s.usuario_email?' <'+s.usuario_email+'>':'')}):null,
  s.pagina?el('span',{text:'Pantalla: '+s.pagina}):null);
 const caja=el('article',{class:'sug-item'},meta,el('p',{class:'sug-texto',text:s.comentario}));
 if(s.adjuntos.length){
  const adj=el('div',{class:'sug-adjuntos'});
  s.adjuntos.forEach(a=>{const url=SUG_API+'?adjunto='+a.id;const enlace=el('a',{href:url,target:'_blank',rel:'noopener'});
   if(['png','jpg','jpeg','gif','webp'].includes(a.extension))enlace.append(el('img',{src:url,alt:a.nombre,loading:'lazy'}));
   enlace.append(el('span',{text:a.nombre+' · '+tamano(a.bytes)}));adj.append(enlace);});
  caja.append(adj);
 }
 if(sug.esAdmin)caja.append(controlesAdmin(s));
 else if(s.nota_admin)caja.append(el('p',{class:'sug-nota',text:'Respuesta: '+s.nota_admin}));
 return caja;
}

function controlesAdmin(s){
 const estado=el('select',{'aria-label':'Estado del reporte #'+s.id});
 Object.entries(SUG_ESTADOS).forEach(([v,t])=>{const o=el('option',{value:v,text:t});if(v===s.estado)o.selected=true;estado.append(o);});
 const nota=el('textarea',{rows:'1',maxlength:'2000',placeholder:'Nota para quien reportó (opcional)','aria-label':'Nota del reporte #'+s.id});nota.value=s.nota_admin||'';
 const guardar=el('button',{type:'button',text:'Guardar'});
 guardar.addEventListener('click',async()=>{
  guardar.disabled=true;
  try{
   const r=await fetch(SUG_API+'?id='+s.id,{method:'PATCH',headers:{'Content-Type':'application/json','X-Icontador':'1',Accept:'application/json'},credentials:'same-origin',body:JSON.stringify({estado:estado.value,nota_admin:nota.value})});
   const j=await r.json().catch(()=>({}));if(!r.ok)throw new Error(j.error||'No se pudo guardar.');
   cargar();
  }catch(e){alert(e.message);guardar.disabled=false;}
 });
 return el('div',{class:'sug-admin'},estado,nota,guardar);
}

function origen(){return new URLSearchParams(location.search).get('origen')||'';}
function iniciar(){
 const o=origen();$('sug-pagina').value=o;
 const desde=new URLSearchParams(location.search).get('desde');
 if(desde&&/^[A-Za-z0-9_-]+\.html$/.test(desde))$('sug-volver').href=desde;
 $('sug-form').addEventListener('submit',enviar);
 $('sug-archivos').addEventListener('change',e=>{agregarArchivos(e.target.files);e.target.value='';});
 document.addEventListener('paste',e=>{const files=[...(e.clipboardData?.files||[])].filter(f=>f.type.startsWith('image/'));if(!files.length)return;e.preventDefault();
  agregarArchivos(files.map((f,i)=>new File([f],'captura-'+new Date().toISOString().slice(0,19).replace(/[:T]/g,'-')+(i?'-'+i:'')+'.'+(f.type.split('/')[1]||'png').replace('jpeg','jpg'),{type:f.type})));});
 $('sug-filtros').addEventListener('click',e=>{const b=e.target.closest('button[data-estado]');if(!b)return;sug.estado=b.dataset.estado;$('sug-filtros').querySelectorAll('button').forEach(x=>x.classList.toggle('activo',x===b));cargar();});
 cargar();
}
iniciar();
