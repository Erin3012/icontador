'use strict';
// Pantalla Empresas conectada a api/empresas.php: listar, seleccionar y administrar empresas.
(function(){
const API='../api/empresas.php';
const SII_API='../api/sii.php';
const $=(s,r=document)=>r.querySelector(s);
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
async function api(consulta,opciones={}){
 let respuesta;
 try{respuesta=await fetch(API+(consulta||''),{...opciones,headers:{Accept:'application/json','Content-Type':'application/json'}});}
 catch{throw new Error('No se pudo conectar con la API PHP. Inicie la copia con npm run start:php.');}
 const datos=await respuesta.json().catch(()=>null);
 if(!datos)throw new Error('La API PHP no respondió. Inicie la copia con npm run start:php.');
 if(!respuesta.ok)throw new Error((datos.errores||[datos.error||'Error '+respuesta.status]).join(' '));
 return datos;
}
function aviso(texto){document.querySelector('.offline-message')?.remove();const e=document.createElement('div');e.className='offline-message';e.setAttribute('role','status');e.textContent=texto;document.body.append(e);setTimeout(()=>e.remove(),5000);}
function seleccionar(empresa){localStorage.setItem(EMPRESA_LOCAL_KEY,JSON.stringify(empresa));location.href='panel.html';}
function modal(titulo,contenido){
 document.querySelectorAll('.empresas-modal-nativo[open]').forEach(x=>x.close());
 document.querySelectorAll('.empresas-modal-nativo').forEach(x=>x.remove());
 const caja=document.createElement('dialog');caja.className='empresas-modal-nativo';caja.dataset.conta='';caja.setAttribute('aria-label',titulo);
 caja.innerHTML=`<header><h2>${esc(titulo)}</h2><button type="button" class="empresas-modal-cerrar" aria-label="Cerrar">×</button></header><div class="empresas-modal-contenido" data-conta="">${contenido}</div>`;
 document.body.append(caja);const cerrar=()=>{if(caja.open)caja.close();caja.remove();};
 caja.querySelector('.empresas-modal-cerrar').addEventListener('click',cerrar);
 caja.addEventListener('click',e=>{if(e.target===caja){const r=caja.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)cerrar();}});
 caja.addEventListener('close',()=>caja.remove(),{once:true});
 if(typeof caja.showModal==='function')caja.showModal();else{caja.setAttribute('open','');caja.style.position='fixed';caja.style.inset='5vh auto auto 50%';caja.style.transform='translateX(-50%)';}
 return{overlay:caja,caja,cerrar};
}
function configurarVisibilidadClave(input,boton){
 if(!input||!boton)return;
 boton.addEventListener('click',()=>{
  const mostrar=input.type==='password';
  input.type=mostrar?'text':'password';
  boton.textContent=mostrar?'Ocultar':'Mostrar';
  boton.setAttribute('aria-pressed',String(mostrar));
  boton.setAttribute('aria-label',`${mostrar?'Ocultar':'Mostrar'} Clave Tributaria`);
 });
}

function iniciar(){
 const tabla=$('#listEmpresas');if(!tabla)return;
 const cuerpo=tabla.tBodies[0],info=$('#listEmpresas_info'),contenedor=tabla.closest('form')||tabla;
 contenedor.dataset.conta='';
 $('#listEmpresas_paginate')?.setAttribute('hidden','');$('#listEmpresas_length')?.setAttribute('hidden','');
 let empresas=[],puedeAdministrar=false;
 let mostrarTodas=false;
 let busqueda='';
 const filtro=$('#estEmpr');
 if(filtro){filtro.innerHTML='<option value="activo">Activas</option><option value="inactivo">Inactivas</option><option value="todas">Todas</option>';filtro.value='activo';}

 const panel=document.createElement('div');panel.className='empresas-panel';panel.dataset.conta='';
 panel.innerHTML=`<div id="empresas-vista-admin" hidden>
   <a href="../auth/usuario-empresas.php" class="btn-minimalist">Elegir empresas visibles</a>
   <span id="empresas-vista-temporal" hidden><button type="button" id="empresas-cambiar-vista" class="btn-minimalist">Ver todas por ahora</button></span>
  </div>
  <form class="empresas-crear" hidden>
   <h3>Crear empresa</h3>
   <div class="empresas-campos">
    <label>Razón social *<input name="razon_social" class="form-control input-minimalist" required maxlength="191"></label>
    <label>RUT<span class="empresas-rut"><input name="rut" class="form-control input-minimalist" maxlength="12" placeholder="76.123.456-7"><a class="btn-minimalist" href="https://www.sii.cl/como_se_hace_para/situacion_trib_terceros.html" target="_blank" rel="noopener noreferrer">Consulta pública del SII</a></span></label>
    <label>Giro<input name="giro" class="form-control input-minimalist" maxlength="120"></label>
    <label>Régimen tributario<select name="regimen" class="form-control input-minimalist"><option value="">Seleccione</option><option>Pro Pyme General (14 D N°3)</option><option>Pro Pyme Transparente (14 D N°8)</option><option>Régimen General (14 A)</option><option>Renta presunta</option><option>Sin fines de lucro</option></select></label>
    <label>Teléfono<input name="telefono" class="form-control input-minimalist" maxlength="30"></label>
    <label>E-mail<input name="email" type="email" class="form-control input-minimalist" maxlength="120"></label>
    <label>Domicilio<input name="domicilio" class="form-control input-minimalist" maxlength="191"></label>
    <label>Comuna<input name="comuna" class="form-control input-minimalist" maxlength="80"></label>
    <label>Región<input name="region" class="form-control input-minimalist" maxlength="80"></label>
   </div>
   <section class="empresas-sii-consulta" aria-labelledby="empresas-sii-titulo">
    <h4 id="empresas-sii-titulo">Completar desde el SII</h4>
    <p>Al consultar MiSII, la clave quedará en memoria solo hasta crear la empresa y entonces se guardará cifrada para sincronizar el RCV. Si cancelas, no se guardará.</p>
    <div class="empresas-sii-credenciales"><div class="empresas-clave-entrada"><label for="empresas-sii-clave">Clave Tributaria</label><div class="empresas-clave-control"><input type="password" id="empresas-sii-clave" autocomplete="new-password" maxlength="200"><button type="button" class="empresas-clave-toggle" id="empresas-sii-clave-toggle" aria-controls="empresas-sii-clave" aria-pressed="false" aria-label="Mostrar Clave Tributaria">Mostrar</button></div></div><button type="button" class="btn-minimalist" id="empresas-sii-consultar">Consultar datos</button></div>
    <div id="empresas-sii-estado" role="status" aria-live="polite"></div>
    <div id="empresas-sii-resumen" hidden></div>
   </section>
   <label class="empresas-check"><input type="checkbox" name="plan_ejemplo" checked> Cargar el plan de cuentas de ejemplo (63 cuentas)</label>
   <div class="empresas-botones"><button type="submit" class="btn-minimalist btn-minimalist-royal">Crear empresa</button> <button type="button" class="btn-minimalist empresas-cancelar">Cancelar</button></div>
  </form>
  `;
 contenedor.before(panel);
 const controlesVista=$('#empresas-vista-admin',panel),cambiarVista=$('#empresas-cambiar-vista',panel);
 const vistaTemporal=$('#empresas-vista-temporal',panel);
 const crear=$('.empresas-crear',panel);
 const siiEstado=$('#empresas-sii-estado',crear),siiResumen=$('#empresas-sii-resumen',crear),siiClave=$('#empresas-sii-clave',crear),siiConsultar=$('#empresas-sii-consultar',crear);
 configurarVisibilidadClave(siiClave,$('#empresas-sii-clave-toggle',crear));
 let claveSiiPendiente='',rutClaveSiiPendiente='';
 async function guardarClaveEmpresa(empresaId,tipo,clave){
  const csrfResp=await fetch(SII_API+'?csrf=1',{credentials:'same-origin',headers:{Accept:'application/json'}}),csrfData=await csrfResp.json();if(!csrfResp.ok)throw new Error(csrfData.error||'La sesión venció. Recarga la página.');
  const resp=await fetch(SII_API+`?empresa_id=${empresaId}`,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfData.csrf,Accept:'application/json'},body:JSON.stringify(clave===undefined?{tipo}:{tipo,clave})});
  const data=await resp.json();if(!resp.ok)throw new Error(data.error||'No se pudo guardar la Clave Tributaria.');return data;
 }
 crear.elements.rut.addEventListener('input',()=>{if(rutClaveSiiPendiente&&crear.elements.rut.value.trim()!==rutClaveSiiPendiente){claveSiiPendiente='';rutClaveSiiPendiente='';crear.dataset.fichaSii='';siiResumen.hidden=true;siiEstado.textContent='Cambió el RUT. Vuelve a consultar MiSII para asociar los datos y la clave correctos.';}});
 async function consultarDatosSii(){
  const rut=crear.elements.rut.value.trim();let clave=siiClave.value;
  if(!rut||!clave){siiEstado.textContent='Ingresa primero el RUT de la empresa y su Clave Tributaria.';return;}
  claveSiiPendiente='';rutClaveSiiPendiente='';siiConsultar.disabled=true;crear.elements.rut.disabled=true;crear.querySelector('[type="submit"]').disabled=true;siiEstado.textContent='Comprobando conexión con el agente SII…';siiResumen.hidden=true;
  let claveTemporal=clave;siiClave.value='';
  try{
   const estado=await fetch(SII_API+'?servicio=1',{credentials:'same-origin',headers:{Accept:'application/json'}}).then(async r=>{const j=await r.json();if(!r.ok)throw new Error(j.error||'No se pudo consultar el agente.');return j;});
   if(!estado.disponible)throw new Error('El agente SII está desconectado. Vuelve a intentarlo cuando esté conectado.');
   const csrf=await fetch(SII_API+'?csrf=1',{credentials:'same-origin',headers:{Accept:'application/json'}}).then(async r=>{const j=await r.json();if(!r.ok)throw new Error(j.error||'La sesión venció.');return j.csrf;});
   const resp=await fetch(SII_API,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf,Accept:'application/json'},body:JSON.stringify({tipo:'datos_empresa',rut,clave:claveTemporal})});
   claveTemporal='';
   const tarea=await resp.json();if(!resp.ok)throw new Error(tarea.error||'No se pudo iniciar la consulta.');
   siiEstado.textContent='Consulta enviada. Esperando respuesta de MiSII…';
   const inicio=Date.now();let resultado;
   while(Date.now()-inicio<120000){
    await new Promise(resolve=>setTimeout(resolve,2000));
    const r=await fetch(SII_API+'?tarea='+encodeURIComponent(tarea.id),{credentials:'same-origin',headers:{Accept:'application/json'}});
    const actual=await r.json();if(!r.ok)throw new Error(actual.error||'No se pudo leer el estado de la consulta.');
    if(actual.estado==='completada'){resultado=actual.datos;break;}
    if(actual.estado==='error')throw new Error(actual.mensaje||'El SII no pudo completar la consulta.');
   }
   if(!resultado)throw new Error('La consulta sigue pendiente. Puedes volver a intentarlo más tarde.');
   if(crear.elements.rut.value.trim()!==rut)throw new Error('El RUT cambió durante la consulta. Vuelve a consultar los datos.');
   for(const campo of ['razon_social','rut','giro','regimen','telefono','email','domicilio','comuna','region','fecha_constitucion','fecha_inicio_actividades','categoria_tributaria','tipo_contribuyente','subtipo_contribuyente','segmento','glosa_descriptiva'])if(resultado[campo]&&crear.elements[campo])crear.elements[campo].value=resultado[campo];
   crear.dataset.fichaSii=JSON.stringify(resultado);
   claveSiiPendiente=clave;rutClaveSiiPendiente=rut;
   const lista=[['Razón social',resultado.razon_social],['Giro',resultado.giro],['Régimen',resultado.regimen],['Domicilio',[resultado.domicilio,resultado.comuna,resultado.region].filter(Boolean).join(', ')],['Fecha de constitución',resultado.fecha_constitucion],['Inicio de actividades',resultado.fecha_inicio_actividades],['Categoría tributaria',resultado.categoria_tributaria],['Tipo de contribuyente',[resultado.tipo_contribuyente,resultado.subtipo_contribuyente,resultado.segmento].filter(Boolean).join(' · ')],['Glosa descriptiva',resultado.glosa_descriptiva],['Actividades registradas',(resultado.actividades||[]).map(x=>`${x.descripcion||''}${x.codigo?` (${x.codigo})`:''}`).filter(Boolean).join('; ')],['Sucursales',(resultado.sucursales||[]).map(x=>`${x.tipo||''}: ${x.direccion||''}`).filter(Boolean).join('; ')],['Características tributarias',(resultado.caracteristicas||[]).map(x=>x.nombre).filter(Boolean).join('; ')],['Documentos autorizados',(resultado.documentos_autorizados||[]).map(x=>x.tipo).filter(Boolean).join('; ')]];
   siiResumen.replaceChildren();const dl=document.createElement('dl');dl.className='empresas-sii-datos';for(const [etiqueta,valor] of lista){if(!valor)continue;const dt=document.createElement('dt');dt.textContent=etiqueta;const dd=document.createElement('dd');dd.textContent=valor;dl.append(dt,dd);}siiResumen.append(dl);siiResumen.hidden=false;
   siiEstado.textContent='Datos obtenidos. Al crear la empresa, esta clave se guardará cifrada para el RCV.';
  }catch(err){claveSiiPendiente='';rutClaveSiiPendiente='';claveTemporal='';crear.dataset.fichaSii='';siiEstado.textContent=err.message;}
  finally{siiConsultar.disabled=false;crear.elements.rut.disabled=false;crear.querySelector('[type="submit"]').disabled=false;clave='';claveTemporal='';}
 }
 siiConsultar.addEventListener('click',consultarDatosSii);

 const renderizar=()=>{
  const actual=empresaSeleccionada()?.id,estado=filtro?.value||'activo';
  const visibles=empresas.filter(e=>{
   const coincideEstado=estado==='todas'||(estado==='inactivo'?(e.estado||'activo')==='inactivo':(e.estado||'activo')==='activo');
   return coincideEstado&&(!busqueda||[e.razon_social,e.rut,e.regimen,e.telefono,e.email].some(v=>String(v||'').toLowerCase().includes(busqueda)));
  });
  cuerpo.innerHTML=visibles.length?visibles.map((e,i)=>{
   const inactiva=(e.estado||'activo')==='inactivo';
   const editarBtn='<a href="#" class="js_cargarEditarEmpresa puntero btn-accion-empresa" data-accion="editar" title="Editar" aria-label="Editar"><span class="btn-action-glow btn-glow-celeste"><i class="fa fa-pencil" aria-hidden="true"></i></span></a>';
   const verBtn='<a href="#" class="js_verEmpresa puntero btn-accion-empresa" data-accion="ver" title="Ver" aria-label="Ver"><span class="btn-action-glow btn-glow-cafe-cl"><i class="fa fa-eye" aria-hidden="true"></i></span></a>';
   const estadoBtn=`<a href="#" class="js_cargarEliminarEmpresa puntero btn-accion-empresa" data-accion="estado" title="${inactiva?'Activar':'Desactivar'}" aria-label="${inactiva?'Activar':'Desactivar'}"><span class="btn-action-glow ${inactiva?'btn-glow-rojo':'btn-glow-verde'}"><i class="fa ${inactiva?'fa-times':'fa-check'}" aria-hidden="true"></i></span></a>`;
   return `<tr class="${i%2?'even':'odd'}${e.id===actual?' empresas-actual':''}${inactiva?' empresas-inactiva':''}" data-id="${e.id}">
    <td class="td-empresa-corta empresas-ingresar" style="cursor:pointer"><span class="empresas-nombre-escritorio" title="Doble clic para ingresar a esta empresa">${esc(e.razon_social)}</span><button type="button" class="empresas-nombre-movil" style="display:none">${esc(e.razon_social)}<span>${esc(e.rut)}</span></button>${e.id===actual?' <span class="empresas-etiqueta">seleccionada</span>':''}${inactiva?' <span class="empresas-etiqueta empresas-etiqueta-inactiva">inactiva</span>':''}</td><td>${esc(e.rut)}</td><td class="texto_izq">${esc(e.regimen)}</td><td>${esc(e.telefono)}</td><td>${esc(e.email)}</td>
    <td class="empresas-acciones-celda" style="box-sizing:content-box;width:146.075px !important;min-width:146.075px !important;max-width:146.075px !important;text-align:center !important;white-space:nowrap !important">${editarBtn}${verBtn}${estadoBtn}</td></tr>`;
  }).join(''):'<tr><td colspan="6" class="dataTables_empty">No hay empresas en este filtro.</td></tr>';
  if(info)info.textContent=`Mostrando ${visibles.length} de ${empresas.length} ${empresas.length===1?'empresa':'empresas'}`;
 };
 const pintar=async()=>{
  try{
   const datos=await api(mostrarTodas?'?vista=todas':'');
   empresas=datos.empresas||[];
   puedeAdministrar=Boolean(datos.puede_administrar);
   controlesVista.hidden=!puedeAdministrar;
   vistaTemporal.hidden=!datos.vista_personal;
   cambiarVista.textContent=mostrarTodas?'Volver a mi selección':'Ver todas por ahora';
   const elegida=empresaSeleccionada();
   if(elegida&&(!datos.vista_personal||mostrarTodas)&&!empresas.some(e=>e.id===elegida.id))localStorage.removeItem(EMPRESA_LOCAL_KEY);
   renderizar();
   return true;
  }
  catch(e){cuerpo.innerHTML=`<tr><td colspan="6" class="dataTables_empty">${esc(e.message)}</td></tr>`;if(info)info.textContent='';return false;}
 };
 cambiarVista.addEventListener('click',async()=>{
  cambiarVista.disabled=true;
  mostrarTodas=!mostrarTodas;
  if(!await pintar())mostrarTodas=!mostrarTodas;
  cambiarVista.disabled=false;
 });
 filtro?.addEventListener('change',renderizar);
 $('#listEmpresas_filter input')?.addEventListener('input',e=>{busqueda=e.target.value.trim().toLowerCase();renderizar();});
 const ingresarDesdeFila=control=>{
  const fila=control?.closest('tr[data-id]');if(!fila)return;
  const empresa=empresas.find(x=>x.id===Number(fila.dataset.id));if(!empresa)return;
  if(empresa.estado==='inactivo'){aviso('Activa esta empresa antes de ingresar.');return;}
  seleccionar(empresa);
 };
 cuerpo.addEventListener('dblclick',e=>ingresarDesdeFila(e.target.closest('.empresas-ingresar')));
 cuerpo.addEventListener('click',async e=>{
  const nombreMovil=e.target.closest('.empresas-nombre-movil');
  if(nombreMovil){ingresarDesdeFila(nombreMovil);return;}
  const b=e.target.closest('[data-accion]');if(!b)return;
  e.preventDefault();
  const empresa=empresas.find(x=>x.id===Number(b.closest('tr').dataset.id));if(!empresa)return;
  if(b.dataset.accion==='ver'){
   const datos=[['Razón social',empresa.razon_social],['RUT',empresa.rut],['Giro',empresa.giro],['Régimen tributario',empresa.regimen],['Teléfono',empresa.telefono],['E-mail',empresa.email],['Domicilio',empresa.domicilio],['Comuna',empresa.comuna],['Región',empresa.region],['Fecha de constitución',empresa.fecha_constitucion],['Inicio de actividades',empresa.fecha_inicio_actividades],['Categoría tributaria',empresa.categoria_tributaria],['Tipo de contribuyente',empresa.tipo_contribuyente],['Subtipo',empresa.subtipo_contribuyente],['Segmento',empresa.segmento],['Glosa descriptiva',empresa.glosa_descriptiva],['Estado',empresa.estado==='inactivo'?'Inactiva':'Activa'],['Vistas importadas',empresa.vistas],['Registros importados',empresa.registros],['Cuentas',empresa.cuentas],['Vouchers',empresa.vouchers]];
   const ficha=empresa.ficha_sii||{};const colecciones=[['Actividades SII',ficha.actividades],['Sucursales SII',ficha.sucursales],['Características tributarias',ficha.caracteristicas],['Documentos autorizados',ficha.documentos_autorizados]];
   const resumen=colecciones.filter(([,xs])=>Array.isArray(xs)&&xs.length).map(([titulo,xs])=>`<section class="empresas-sii-coleccion"><h3>${esc(titulo)}</h3><ul>${xs.map(x=>`<li>${esc(Object.values(x||{}).filter(Boolean).join(' · '))}</li>`).join('')}</ul></section>`).join('');
   const vista=modal('Ver empresa',`<dl class="empresas-detalle">${datos.map(([k,v])=>`<div><dt>${esc(k)}</dt><dd>${esc(v||'—')}</dd></div>`).join('')}</dl>${resumen}`);vista.caja.querySelector('.empresas-modal-cerrar').focus();return;
  }
  if(!puedeAdministrar){aviso('Solo un administrador puede modificar empresas.');return;}
  if(b.dataset.accion==='editar'){
   const regimenes=['Pro Pyme General (14 D N°3)','Pro Pyme Transparente (14 D N°8)','Régimen General (14 A)','Renta presunta','Sin fines de lucro'];if(empresa.regimen&&!regimenes.includes(empresa.regimen))regimenes.unshift(empresa.regimen);
   const edicion=modal('Editar empresa',`<div class="empresas-editar-ayuda">Revisa y actualiza los antecedentes de esta empresa. Los cambios se guardan en su ficha de qlc.cl.</div>
    <form class="empresas-editar">
     <section class="empresas-editar-seccion"><h3>Datos de la empresa</h3><div class="empresas-editar-grid">
      <label class="campo-ancho">Razón social *<input name="razon_social" required maxlength="191" value="${esc(empresa.razon_social)}"></label>
      <label>RUT<input name="rut" maxlength="12" value="${esc(empresa.rut)}"></label>
      <label>Tipo de contribuyente<input name="tipo_contribuyente" maxlength="80" value="${esc(empresa.tipo_contribuyente)}"></label>
      <label>Giro<input name="giro" maxlength="120" value="${esc(empresa.giro)}"></label>
      <label>Régimen tributario<select name="regimen"><option value="">Sin especificar</option>${regimenes.map(r=>`<option value="${esc(r)}" ${r===empresa.regimen?'selected':''}>${esc(r)}</option>`).join('')}</select></label>
      <label>Subtipo<input name="subtipo_contribuyente" maxlength="80" value="${esc(empresa.subtipo_contribuyente)}"></label>
      <label>Categoría tributaria<input name="categoria_tributaria" maxlength="40" value="${esc(empresa.categoria_tributaria)}"></label>
      <label>Segmento<input name="segmento" maxlength="60" value="${esc(empresa.segmento)}"></label>
     </div></section>
     <section class="empresas-editar-seccion"><h3>Contacto y domicilio</h3><div class="empresas-editar-grid">
      <label>Correo electrónico<input name="email" type="email" maxlength="120" value="${esc(empresa.email)}"></label>
      <label>Teléfono<input name="telefono" maxlength="30" value="${esc(empresa.telefono)}"></label>
      <label class="campo-ancho">Domicilio<input name="domicilio" maxlength="191" value="${esc(empresa.domicilio)}"></label>
      <label>Comuna<input name="comuna" maxlength="80" value="${esc(empresa.comuna)}"></label>
      <label>Región<input name="region" maxlength="80" value="${esc(empresa.region)}"></label>
     </div></section>
     <section class="empresas-editar-seccion"><h3>Antecedentes tributarios</h3><div class="empresas-editar-grid">
      <label>Fecha de constitución<input name="fecha_constitucion" maxlength="20" value="${esc(empresa.fecha_constitucion)}"></label>
      <label>Inicio de actividades<input name="fecha_inicio_actividades" maxlength="20" value="${esc(empresa.fecha_inicio_actividades)}"></label>
      <label class="campo-ancho">Glosa descriptiva<input name="glosa_descriptiva" maxlength="191" value="${esc(empresa.glosa_descriptiva)}"></label>
     </div></section>
     <section class="empresas-editar-seccion empresas-clave-sii"><h3>Clave Tributaria del SII</h3>
      <p>Se guarda cifrada para las sincronizaciones RCV de esta empresa. Nunca se muestra nuevamente.</p>
      <p class="empresas-clave-sii-estado" role="status" aria-live="polite">Consultando estado…</p>
      <div class="empresas-clave-entrada"><label for="empresas-clave-sii-${Number(empresa.id)}">Clave Tributaria</label><div class="empresas-clave-control"><input type="password" id="empresas-clave-sii-${Number(empresa.id)}" name="clave_sii" autocomplete="new-password" maxlength="200"><button type="button" class="empresas-clave-toggle" aria-controls="empresas-clave-sii-${Number(empresa.id)}" aria-pressed="false" aria-label="Mostrar Clave Tributaria">Mostrar</button></div></div>
      <div class="empresas-clave-sii-botones"><button type="button" class="empresas-clave-sii-guardar">Guardar o reemplazar clave</button><button type="button" class="empresas-clave-sii-eliminar" hidden>Eliminar clave</button></div>
     </section>
     <div class="empresas-modal-botones"><button type="button" class="empresas-cancelar-edicion">Cancelar</button><button class="btn-minimalist btn-minimalist-royal" type="submit">Guardar cambios</button></div>
    </form>`);
   edicion.caja.querySelector('.empresas-cancelar-edicion').addEventListener('click',edicion.cerrar);
   const form=edicion.caja.querySelector('form');
   const claveEstado=form.querySelector('.empresas-clave-sii-estado'),claveInput=form.elements.clave_sii,guardarClave=form.querySelector('.empresas-clave-sii-guardar'),eliminarClave=form.querySelector('.empresas-clave-sii-eliminar');
   configurarVisibilidadClave(claveInput,form.querySelector('.empresas-clave-sii .empresas-clave-toggle'));
   async function consultarEstadoClave(){
    try{const r=await fetch(SII_API+`?panel=1&empresa_id=${empresa.id}`,{credentials:'same-origin',headers:{Accept:'application/json'}});const d=await r.json();if(!r.ok)throw new Error(d.error||'No se pudo consultar el estado de la clave.');
     claveEstado.textContent=!d.cifrado_disponible?'El servidor no tiene disponible el cifrado seguro.':d.clave_configurada?'Hay una clave guardada de forma segura.':'No hay una clave guardada.';
     guardarClave.disabled=!d.cifrado_disponible;eliminarClave.hidden=!d.clave_configurada;
    }catch(err){claveEstado.textContent=err.message;guardarClave.disabled=true;eliminarClave.hidden=true;}
   }
   async function enviarOperacionClave(tipo,clave){
    const csrfResp=await fetch(SII_API+'?csrf=1',{credentials:'same-origin',headers:{Accept:'application/json'}}),csrfData=await csrfResp.json();if(!csrfResp.ok)throw new Error(csrfData.error||'La sesión venció. Recarga la página.');
    const r=await fetch(SII_API+`?empresa_id=${empresa.id}`,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfData.csrf,Accept:'application/json'},body:JSON.stringify(clave===undefined?{tipo}:{tipo,clave})});
    const d=await r.json();if(!r.ok)throw new Error(d.error||'No se pudo actualizar la clave.');return d;
   }
   guardarClave.addEventListener('click',async()=>{let clave=claveInput.value;if(!clave){claveEstado.textContent='Ingresa la Clave Tributaria para guardarla.';claveInput.focus();return;}guardarClave.disabled=true;claveInput.value='';
    try{await enviarOperacionClave('guardar_clave',clave);claveEstado.textContent='Clave guardada de forma segura.';}
    catch(err){claveEstado.textContent=err.message;}
    finally{clave='';claveInput.value='';guardarClave.disabled=false;await consultarEstadoClave();}
   });
   eliminarClave.addEventListener('click',async()=>{if(!window.confirm(`¿Eliminar la Clave Tributaria guardada para ${empresa.razon_social}?`))return;eliminarClave.disabled=true;
    try{await enviarOperacionClave('eliminar_clave');claveEstado.textContent='Clave eliminada.';await consultarEstadoClave();}
    catch(err){claveEstado.textContent=err.message;}
    finally{eliminarClave.disabled=false;}
   });
   consultarEstadoClave();
   form.addEventListener('submit',async ev=>{ev.preventDefault();const guardar=form.querySelector('.empresas-modal-botones [type=submit]');guardar.disabled=true;
    try{const datos=Object.fromEntries(new FormData(form));delete datos.clave_sii;const r=await api(`?id=${empresa.id}`,{method:'PUT',body:JSON.stringify(datos)});const actual=empresaSeleccionada();if(actual?.id===empresa.id)localStorage.setItem(EMPRESA_LOCAL_KEY,JSON.stringify(r.empresa));edicion.cerrar();aviso('Cambios guardados.');await pintar();}
    catch(err){aviso(err.message);}finally{guardar.disabled=false;}
   });form.querySelector('[name=razon_social]').focus();return;
  }
  if(b.dataset.accion==='estado'){
   const inactiva=empresa.estado==='inactivo',nuevo=inactiva?'activo':'inactivo';
   if(!window.confirm(`${inactiva?'¿Activar':'¿Desactivar'} ${empresa.razon_social}? ${inactiva?'':'La empresa dejará de aparecer en el filtro Activas.'}`))return;
   b.disabled=true;try{await api(`?id=${empresa.id}`,{method:'PATCH',body:JSON.stringify({estado:nuevo})});if(nuevo==='inactivo'&&empresaSeleccionada()?.id===empresa.id)localStorage.removeItem(EMPRESA_LOCAL_KEY);aviso(`Empresa ${inactiva?'activada':'desactivada'}.`);await pintar();}catch(err){aviso(err.message);}finally{b.disabled=false;}
  }
 });
 // "Crear Empresa" del menú de acciones abre el formulario.
 document.addEventListener('click',e=>{const a=e.target.closest('a.ic-btn-action-nav');if(!a||!/Crear Empresa/.test(a.textContent))return;
  e.preventDefault();e.stopPropagation();crear.hidden=false;crear.razon_social.focus();},true);
 $('.empresas-cancelar',panel).addEventListener('click',()=>{claveSiiPendiente='';rutClaveSiiPendiente='';crear.reset();crear.dataset.fichaSii='';siiClave.value='';siiEstado.textContent='';siiResumen.replaceChildren();siiResumen.hidden=true;crear.hidden=true;});
 crear.addEventListener('submit',async e=>{e.preventDefault();const b=crear.querySelector('[type=submit]');b.disabled=true;
  const datos=Object.fromEntries(new FormData(crear));datos.plan_ejemplo=crear.plan_ejemplo.checked;
  try{datos.ficha_sii=crear.dataset.fichaSii?JSON.parse(crear.dataset.fichaSii):null;}catch{datos.ficha_sii=null;}
  try{const r=await api('',{method:'POST',body:JSON.stringify(datos)});let mensajeClave='';
   if(claveSiiPendiente){
    try{await guardarClaveEmpresa(r.empresa.id,'guardar_clave',claveSiiPendiente);mensajeClave=' La Clave Tributaria quedó guardada cifrada para sincronizar el RCV.';}
    catch{mensajeClave=' La empresa se creó, pero no se pudo guardar la Clave Tributaria. Puedes guardarla desde Empresas → Editar empresa.';}
   }
   claveSiiPendiente='';rutClaveSiiPendiente='';crear.reset();crear.dataset.fichaSii='';siiEstado.textContent='';siiResumen.replaceChildren();siiResumen.hidden=true;crear.hidden=true;
   if(!vistaTemporal.hidden)mostrarTodas=true;
   aviso(`Empresa ${r.empresa.razon_social} creada`+(r.cuentas_agregadas?` con ${r.cuentas_agregadas} cuentas de ejemplo.`:'.')+mensajeClave);await pintar();}
  catch(err){aviso(err.message);}finally{b.disabled=false;}});
 pintar();
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',iniciar);else iniciar();
})();
