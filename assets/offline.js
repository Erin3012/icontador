'use strict';
// Las zonas marcadas con data-conta tienen su propio comportamiento en contabilidad.js.
document.addEventListener('submit',event=>{if(event.target.closest('[data-conta]'))return;event.preventDefault();notify();});
document.addEventListener('click',event=>{
 if(event.target.closest('[data-conta]'))return;
 const accordion=event.target.closest('.ui-accordion-header[aria-controls]');if(accordion){event.preventDefault();const panel=document.getElementById(accordion.getAttribute('aria-controls'));if(panel){const open=accordion.getAttribute('aria-expanded')!=='true';accordion.setAttribute('aria-expanded',String(open));panel.setAttribute('aria-hidden',String(!open));panel.style.display=open?'block':'none';}return;}
 const a=event.target.closest('a');
 if(a?.dataset.offlinePanel){event.preventDefault();const tab=a.closest('[role=tab]'),panel=document.getElementById(a.dataset.offlinePanel);const group=tab?.closest('.ui-tabs');group?.querySelectorAll(':scope > [role=tabpanel]').forEach(p=>{p.style.display=p===panel?'block':'none';p.setAttribute('aria-hidden',String(p!==panel));});group?.querySelectorAll('[role=tab]').forEach(t=>{t.classList.toggle('ui-tabs-active',t===tab);t.setAttribute('aria-selected',String(t===tab));});return;}
 if(a&&a.getAttribute('href')==='#'&&(a.classList.contains('ic-trigger-link')||a.parentElement?.querySelector(':scope > ul'))){event.preventDefault();const menu=a.parentElement.querySelector('ul');if(menu){menu.hidden=!menu.hidden;menu.style.display=menu.hidden?'none':'block';return;}}
 if(a&&a.getAttribute('href')!=='#')return;
 const control=event.target.closest('button,a,input[type=submit]');
 if(control){event.preventDefault();const label=control.textContent.trim();if(/^(Cerrar|Close)(\s*✕)?$/.test(label)){control.closest('.modal,.ui-dialog')?.remove();document.querySelectorAll('.modal-backdrop').forEach(e=>e.remove());document.body.classList.remove('modal-open');return;}notify();}
});
document.addEventListener('input',event=>{if(event.target.type!=='search')return;const section=event.target.closest('.dataTables_wrapper');section?.querySelectorAll('tbody tr').forEach(row=>{row.hidden=!row.textContent.toLowerCase().includes(event.target.value.toLowerCase());});});
function notify(){document.querySelector('.offline-message')?.remove();const e=document.createElement('div');e.className='offline-message';e.setAttribute('role','status');e.textContent='Vista de referencia: esta operación requiere el backend y está desactivada.';document.body.append(e);setTimeout(()=>e.remove(),4000);}
