// Prueba de assets/empresa-fetch.js con un navegador simulado: node tools/test-empresa-fetch.cjs
const assert=require('node:assert/strict');const fs=require('node:fs');const path=require('node:path');const vm=require('node:vm');
function correr(empresa){
 const llamadas=[];const almacen=empresa?{'icontador.empresaSeleccionada':JSON.stringify(empresa)}:{};
 const ventana={fetch:async(entrada,opciones)=>{llamadas.push({entrada,opciones});return {ok:true};}};
 const contexto={window:ventana,localStorage:{getItem:k=>almacen[k]??null},location:{href:'http://localhost:4173/views/crm.html',origin:'http://localhost:4173'},URL,Headers};
 vm.runInNewContext(fs.readFileSync(path.join(__dirname,'..','assets','empresa-fetch.js'),'utf8'),contexto);
 return {fetch:ventana.fetch,llamadas};
}
(async()=>{
 let t=correr({id:7});
 await t.fetch('/api/crm.php?accion=dashboard');assert.equal(new Headers(t.llamadas[0].opciones.headers).get('X-Empresa-Id'),'7');
 await t.fetch('../api/dashboard.php');assert.equal(new Headers(t.llamadas[1].opciones.headers).get('X-Empresa-Id'),'7');
 await t.fetch('/api/crm.php',{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'});
 const h=new Headers(t.llamadas[2].opciones.headers);assert.equal(h.get('X-Empresa-Id'),'7');assert.equal(h.get('Content-Type'),'application/json');assert.equal(t.llamadas[2].opciones.method,'POST');
 await t.fetch('/api/rrhh.php?op=x',{headers:{'X-Empresa-Id':'9'}});assert.equal(new Headers(t.llamadas[3].opciones.headers).get('X-Empresa-Id'),'9','no pisa una cabecera explícita');
 await t.fetch(new URL('../api/tesoreria.php?dias=30','http://localhost:4173/views/tesoreria.html'));assert.equal(new Headers(t.llamadas[4].opciones.headers).get('X-Empresa-Id'),'7','también con un objeto URL (tesoreria.html y presupuestos.html lo usan)');
 await t.fetch({url:'http://localhost:4173/api/crm.php?accion=tareas'});assert.equal(new Headers(t.llamadas[5].opciones.headers).get('X-Empresa-Id'),'7','también con un objeto tipo Request');
 await t.fetch('https://cdn.jsdelivr.net/npm/chart.js@4.4.0');assert.equal(t.llamadas[6].opciones,undefined,'no toca otros orígenes');
 await t.fetch('/assets/theme.css');assert.equal(t.llamadas[7].opciones,undefined,'no toca rutas fuera de /api/*.php');
 t=correr(null);await t.fetch('/api/crm.php');assert.equal(t.llamadas[0].opciones,undefined,'sin empresa seleccionada no añade cabecera');
 console.log('empresa-fetch.js: pruebas superadas');
})();
