const {parseHTML}=require('linkedom');
function sanitize(html, assets, url='https://www.icontador.cl/') {
  const {document}=parseHTML(html.replace(/<!--[\s\S]*?-->/g,''));
  document.querySelectorAll('style').forEach(e=>{e.textContent=e.textContent.replace(/@import[^;]+;/gi,'').replace(/url\((?!["']?#)[^)]+\)/gi,'none');});
  document.querySelectorAll('script,iframe,object,embed,base,noscript,input[type=hidden],meta[http-equiv],link:not([rel=stylesheet]),.swal2-container').forEach(e=>e.remove());
  document.querySelectorAll('*').forEach(e=>{
    for(const a of [...e.attributes]) if(/^on|^data-/i.test(a.name)||['integrity','nonce','crossorigin','action','formaction','srcdoc','ping','title','aria-label'].includes(a.name)) e.removeAttribute(a.name);
    if(e.hasAttribute('style')) e.setAttribute('style',e.getAttribute('style').replace(/url\((?!["']?#)[^)]+\)/gi,'none'));
  });
  document.querySelectorAll('input,textarea').forEach(e=>{e.removeAttribute('value');e.removeAttribute('checked');if(e.tagName==='TEXTAREA')e.textContent='';if(e.type==='password')e.setAttribute('disabled','');});
  document.querySelectorAll('a').forEach(e=>{const href=e.getAttribute('href')||'';const match=href.match(/^javascript:\s*([\w$]+)\s*\(/i);if(match)e.setAttribute('data-local-action',match[1]);else{try{const key=new URL(href,url).searchParams.get('finc');if(key&&/^[\w]+$/.test(key))e.setAttribute('data-local-action','finc:'+key);}catch{}}e.setAttribute('href','#');e.removeAttribute('target');});
  document.querySelectorAll('form').forEach(e=>e.removeAttribute('action'));
  document.querySelectorAll('img,link[rel=stylesheet]').forEach(e=>{const attr=e.tagName==='IMG'?'src':'href';let abs;try{abs=new URL(e.getAttribute(attr),url).href;}catch{}if(assets[abs])e.setAttribute(attr,assets[abs]);else e.removeAttribute(attr);e.removeAttribute('srcset');});
  document.querySelectorAll('option').forEach(e=>{if(!/^(\d{1,4}|Enero|Febrero|Marzo|Abril|Mayo|Junio|Julio|Agosto|Septiembre|Octubre|Noviembre|Diciembre|Seleccione.*|Ventas|Compras|Todos.*|Todas.*|Activo.*|Inactivo.*)$/i.test(e.textContent.trim()))e.textContent='Opción de ejemplo';e.setAttribute('value','demo');});
  document.querySelectorAll('.select2-selection__rendered').forEach(e=>{if(!/^(Seleccione.*|Ninguno|Ninguna|Todos.*|Todas.*|Sin Código.*|Sin Centro.*)$/i.test(e.textContent.trim()))e.textContent='Opción de ejemplo';});
  document.querySelectorAll('table.dataTable tbody tr,table[role=grid] tbody tr').forEach((r,i)=>r.querySelectorAll('td').forEach((c,j)=>{if(c.hasAttribute('colspan')&&/Ningún dato|No hay|Sin registros/i.test(c.textContent))return;c.textContent=j===0?String(i+1):'Ejemplo '+(j+1);}));
  document.querySelectorAll('.highcharts-series-group,.highcharts-data-labels,.highcharts-tooltip').forEach(e=>e.remove());
  document.querySelectorAll('a').forEach(e=>{if(/USUARIO TITULAR:/i.test(e.textContent))e.textContent='USUARIO TITULAR: USUARIO DEMO · EMPRESA DEMO SPA';});
  const walk=e=>{for(const n of [...e.childNodes]){if(n.nodeType===3)n.textContent=n.textContent.replace(/[A-ZÁÉÍÓÚÑ][A-ZÁÉÍÓÚÑ ]{10,}EMPRESA INDIVIDUAL DE RESPONSABILIDAD LIMITADA/gi,'EMPRESA DEMO SPA').replace(/[\w.+-]+@[\w.-]+\.[a-z]{2,}/gi,'demo@example.invalid').replace(/\b\d{1,2}(?:\.\d{3}){2}-[\dkK]\b/g,'11.111.111-1').replace(/-?\d{1,3}(?:\.\d{3})+(?:,\d+)?/g,'100.000');else walk(n);}};walk(document.documentElement);
  document.head.insertAdjacentHTML('afterbegin',`<meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; font-src 'self'; script-src 'self'; connect-src 'none'; form-action 'none'; base-uri 'none'"><link rel="stylesheet" href="../assets/offline.css">`);
  document.body.insertAdjacentHTML('afterbegin','<aside class="offline-banner"><a href="../index.html">Índice local</a> · Copia de referencia · Datos ficticios · Operaciones desactivadas</aside>');
  document.body.insertAdjacentHTML('beforeend','<script src="../assets/offline.js"></script>');
  return '<!doctype html>\n'+document.documentElement.outerHTML;
}
module.exports={sanitize};

