'use strict';
// Calculadora de liquidación de sueldo (Chile). Todo el cálculo ocurre en el navegador.
(function(root){
 // Parámetros vigentes para remuneraciones de octubre 2026. Se pueden editar desde la pantalla.
 const PARAMETROS={
  periodo:'Octubre 2026',
  uf:41057.20,            // UF al 30/09/2026 (último día del mes anterior, criterio Previred)
  utm:72151,              // UTM octubre 2026 (SII)
  imm:553553,             // Ingreso mínimo mensual desde 01/05/2026
  topeAfpUf:90,           // Tope imponible AFP y salud
  topeAfcUf:135.2,        // Tope imponible seguro de cesantía
  salud:7,                // Cotización legal de salud
  sis:1.78,               // Seguro de Invalidez y Sobrevivencia, cargo empleador
  reforma:{cuentaIndividual:0.1,rentabilidadProtegida:0.9,expectativaVida:0.72}, // Ley 21.735, desde 08/2026
  mutual:0.93,            // Ley 16.744 tasa básica 0,90% + Ley SANNA 0,03%
  afp:{Capital:11.44,Cuprum:11.44,Habitat:11.27,Modelo:10.58,PlanVital:11.16,Provida:11.45,Uno:10.46},
  afc:{indefinido:{trabajador:0.6,empleador:2.4},plazoFijo:{trabajador:0,empleador:3.0},once:{trabajador:0,empleador:0.8}},
  // Impuesto único de segunda categoría: límite superior de cada tramo en UTM y factor.
  tramos:[[13.5,0],[30,0.04],[50,0.08],[70,0.135],[90,0.23],[120,0.304],[310,0.35],[Infinity,0.40]]
 };
 const r=n=>Math.round(n);

 function impuestoUnico(base,utm,tramos){
  let desde=0,rebaja=0,anterior=0;
  for(const [hasta,factor] of tramos){
   rebaja+=(factor-anterior)*desde*utm;anterior=factor;
   if(base<=hasta*utm)return {impuesto:Math.max(0,r(base*factor-rebaja)),factor,rebaja,desde:desde*utm,hasta:hasta*utm};
   desde=hasta;
  }
 }

 function calcular(e,p=PARAMETROS){
  const sueldo=Math.max(0,+e.sueldoBase||0),otros=Math.max(0,+e.otrosImponibles||0),noImponibles=Math.max(0,+e.noImponibles||0);
  const topeGratificacion=r(4.75*p.imm/12);
  const gratificacion=e.gratificacion?Math.min(r((sueldo+otros)*0.25),topeGratificacion):0;
  const imponible=sueldo+otros+gratificacion;
  const topeAfp=r(p.topeAfpUf*p.uf),topeAfc=r(p.topeAfcUf*p.uf);
  const baseAfp=Math.min(imponible,topeAfp),baseAfc=Math.min(imponible,topeAfc);
  const tasaAfp=p.afp[e.afp]??Object.values(p.afp)[0];
  const afp=r(baseAfp*tasaAfp/100);
  const saludLegal=r(baseAfp*p.salud/100);
  const planIsapre=e.salud==='isapre'?r((+e.planUf||0)*p.uf):0;
  const salud=Math.max(saludLegal,planIsapre);
  const adicionalIsapre=salud-saludLegal;
  const afcTasas=p.afc[e.contrato]||p.afc.indefinido;
  const afc=r(baseAfc*afcTasas.trabajador/100);
  const baseTributable=Math.max(0,imponible-afp-saludLegal-afc);
  const tramo=impuestoUnico(baseTributable,p.utm,p.tramos);
  const otrosDescuentos=Math.max(0,+e.otrosDescuentos||0);
  const descuentosLegales=afp+salud+afc+tramo.impuesto;
  const totalHaberes=imponible+noImponibles;
  const liquido=totalHaberes-descuentosLegales-otrosDescuentos;
  const rf=p.reforma;
  const empleador={
   afc:r(baseAfc*afcTasas.empleador/100),
   sis:r(baseAfp*p.sis/100),
   cuentaIndividual:r(baseAfp*rf.cuentaIndividual/100),
   rentabilidadProtegida:r(baseAfp*rf.rentabilidadProtegida/100),
   expectativaVida:r(baseAfp*rf.expectativaVida/100),
   mutual:r(imponible*p.mutual/100)
  };
  empleador.total=Object.values(empleador).reduce((a,b)=>a+b,0);
  return {sueldo,gratificacion,topeGratificacion,otros,imponible,noImponibles,totalHaberes,topeAfp,topeAfc,baseAfp,baseAfc,
   tasaAfp,afp,saludLegal,salud,adicionalIsapre,tasaAfc:afcTasas.trabajador,afc,baseTributable,tramo,impuesto:tramo.impuesto,
   otrosDescuentos,descuentosLegales,totalDescuentos:descuentosLegales+otrosDescuentos,liquido,empleador,costoEmpresa:totalHaberes+empleador.total};
 }

 const api={PARAMETROS,calcular,impuestoUnico};
 if(typeof module==='object'&&module.exports)module.exports=api;
 root.Liquidacion=api;
 if(typeof document==='undefined')return;

 const $=id=>document.getElementById(id);
 const clp=n=>'$ '+Math.round(n).toLocaleString('es-CL');
 const pct=n=>n.toLocaleString('es-CL',{maximumFractionDigits:3})+' %';
 const num=el=>{const v=String(el.value).replace(/\./g,'').replace(',','.');return v===''?0:+v;};
 function iniciar(){
  const form=$('calcLiquidacion');if(!form)return;
  const afp=$('calcAfp');
  for(const nombre of Object.keys(PARAMETROS.afp)){const o=document.createElement('option');o.value=nombre;o.textContent=nombre+' ('+pct(PARAMETROS.afp[nombre])+')';afp.append(o);}
  afp.value='Habitat';
  $('calcSueldo').value='1000000';
  for(const [id,key] of [['parUf','uf'],['parUtm','utm'],['parImm','imm'],['parTopeAfp','topeAfpUf'],['parTopeAfc','topeAfcUf']])$(id).value=String(PARAMETROS[key]).replace('.',',');
  $('calcPeriodo').textContent=PARAMETROS.periodo;
  form.addEventListener('input',e=>{if(!e.target.closest('#calcGuardar'))actualizar();});form.addEventListener('change',e=>{if(!e.target.closest('#calcGuardar'))actualizar();});
  actualizar();
  iniciarGuardado();
 }

 // Con el servidor PHP (api/liquidaciones.php) las liquidaciones se guardan en la base de datos.
 const API='../api/liquidaciones.php';
 let ultimo=null;
 async function pedir(method,query,body){
  const res=await fetch(API+(query||''),{method,headers:{...(body?{'Content-Type':'application/json'}:{}),...(typeof cabeceraEmpresa==='function'?cabeceraEmpresa():{})},body:body?JSON.stringify(body):undefined});
  const data=await res.json().catch(()=>({}));if(!res.ok)throw new Error(data.error||('Error '+res.status+' del servidor.'));return data;
 }
 async function iniciarGuardado(){
  $('calcMes').value='2026-10';
  $('calcGuardarBtn').disabled=true;
  $('calcGuardarBtn').addEventListener('click',e=>{e.preventDefault();e.stopPropagation();guardar();});
  $('resGuardadas').addEventListener('click',e=>{const b=e.target.closest('[data-borrar]');if(!b)return;e.preventDefault();e.stopPropagation();borrar(b.dataset.borrar);});
  try{await listar();$('calcSinServidor').hidden=true;$('calcGuardarBtn').disabled=false;}catch{$('calcGuardarBtn').hidden=true;}
 }
 async function listar(){
  const {liquidaciones}=await pedir('GET');
  const t=$('resGuardadas');t.replaceChildren();$('calcGuardadas').hidden=!liquidaciones.length;
  for(const l of liquidaciones){
   const tr=document.createElement('tr');
   for(const [texto,clase] of [[l.periodo,''],[l.trabajador,''],[clp(l.imponible),'text-right'],[clp(l.liquido),'text-right']]){const td=document.createElement('td');td.textContent=texto;if(clase)td.className=clase;tr.append(td);}
   const td=document.createElement('td');td.className='text-right';const b=document.createElement('button');b.type='button';b.className='calc-borrar';b.dataset.borrar=l.id;b.textContent='Borrar';b.setAttribute('aria-label','Borrar liquidación de '+l.trabajador);td.append(b);tr.append(td);t.append(tr);
  }
 }
 async function guardar(){
  const msg=$('calcGuardarMsg');
  if(!$('calcTrabajador').value.trim()){msg.textContent='Indica el nombre del trabajador.';return;}
  const x=ultimo.resultado;
  try{
   await pedir('POST','',{periodo:$('calcMes').value,trabajador:$('calcTrabajador').value,sueldoBase:x.sueldo,gratificacion:x.gratificacion,imponible:x.imponible,
    totalHaberes:x.totalHaberes,afp:x.afp,salud:x.salud,afc:x.afc,impuesto:x.impuesto,totalDescuentos:x.totalDescuentos,liquido:x.liquido,costoEmpresa:x.costoEmpresa,
    detalle:{entrada:ultimo.entrada,parametros:{uf:ultimo.parametros.uf,utm:ultimo.parametros.utm,imm:ultimo.parametros.imm,topeAfpUf:ultimo.parametros.topeAfpUf,topeAfcUf:ultimo.parametros.topeAfcUf},
     otros:x.otros,noImponibles:x.noImponibles,adicionalIsapre:x.adicionalIsapre,baseTributable:x.baseTributable,otrosDescuentos:x.otrosDescuentos,empleador:x.empleador}});
   msg.textContent='Liquidación guardada.';await listar();
  }catch(e){msg.textContent=e.message;}
 }
 async function borrar(id){
  try{await pedir('DELETE','?id='+encodeURIComponent(id));$('calcGuardarMsg').textContent='Liquidación borrada.';await listar();}catch(e){$('calcGuardarMsg').textContent=e.message;}
 }
 function fila(tabla,concepto,detalle,monto,clase){
  const tr=document.createElement('tr');if(clase)tr.className=clase;
  for(const [texto,align] of [[concepto,''],[detalle,''],[monto,'text-right']]){const td=document.createElement(clase?'th':'td');td.textContent=texto;if(align)td.className=align;tr.append(td);}
  tabla.append(tr);
 }
 function actualizar(){
  $('calcPlanFila').hidden=$('calcSalud').value!=='isapre';
  const p={...PARAMETROS,uf:num($('parUf')),utm:num($('parUtm')),imm:num($('parImm')),topeAfpUf:num($('parTopeAfp')),topeAfcUf:num($('parTopeAfc'))};
  const entrada={sueldoBase:num($('calcSueldo')),gratificacion:$('calcGratificacion').checked,otrosImponibles:num($('calcOtrosImp')),
   noImponibles:num($('calcNoImp')),afp:$('calcAfp').value,salud:$('calcSalud').value,planUf:num($('calcPlanUf')),contrato:$('calcContrato').value,
   otrosDescuentos:num($('calcOtrosDesc'))};
  const x=calcular(entrada,p);ultimo={entrada,parametros:p,resultado:x};
  const h=$('resHaberes'),d=$('resDescuentos'),e=$('resEmpleador');h.replaceChildren();d.replaceChildren();e.replaceChildren();
  fila(h,'Sueldo base','',clp(x.sueldo));
  if(x.gratificacion)fila(h,'Gratificación legal','25 % con tope de '+clp(x.topeGratificacion)+' (4,75 IMM / 12)',clp(x.gratificacion));
  if(x.otros)fila(h,'Otros haberes imponibles','',clp(x.otros));
  fila(h,'Total imponible','',clp(x.imponible),'info');
  if(x.noImponibles)fila(h,'Haberes no imponibles','Colación, movilización y similares',clp(x.noImponibles));
  fila(h,'Total haberes','',clp(x.totalHaberes),'info');
  const topado=(base,tope)=>base<x.imponible?' sobre tope de '+clp(tope):' sobre '+clp(base);
  fila(d,'AFP '+$('calcAfp').value,pct(x.tasaAfp)+topado(x.baseAfp,x.topeAfp),clp(x.afp));
  fila(d,$('calcSalud').value==='isapre'?'Salud Isapre':'Salud Fonasa','7 %'+topado(x.baseAfp,x.topeAfp),clp(x.saludLegal));
  if(x.adicionalIsapre)fila(d,'Adicional Isapre','Plan pactado sobre el 7 % legal (no rebaja impuesto)',clp(x.adicionalIsapre));
  fila(d,'Seguro de cesantía',x.tasaAfc?pct(x.tasaAfc)+topado(x.baseAfc,x.topeAfc):'Sin cotización del trabajador',clp(x.afc));
  fila(d,'Base tributable','Imponible − AFP − 7 % salud − cesantía',clp(x.baseTributable),'info');
  fila(d,'Impuesto único',x.tramo.factor?'Factor '+x.tramo.factor.toLocaleString('es-CL')+' − rebaja '+clp(x.tramo.rebaja):'Exento (hasta 13,5 UTM)',clp(x.impuesto));
  if(x.otrosDescuentos)fila(d,'Otros descuentos','Anticipos, préstamos y similares',clp(x.otrosDescuentos));
  fila(d,'Total descuentos','',clp(x.totalDescuentos),'info');
  $('resLiquido').textContent=clp(x.liquido);
  const m=x.empleador,rf=p.reforma;
  fila(e,'Seguro de cesantía',pct(p.afc[$('calcContrato').value].empleador),clp(m.afc));
  fila(e,'SIS',pct(p.sis),clp(m.sis));
  fila(e,'Cuenta individual (Ley 21.735)',pct(rf.cuentaIndividual),clp(m.cuentaIndividual));
  fila(e,'Rentabilidad protegida (Ley 21.735)',pct(rf.rentabilidadProtegida),clp(m.rentabilidadProtegida));
  fila(e,'Expectativa de vida (Ley 21.735)',pct(rf.expectativaVida),clp(m.expectativaVida));
  fila(e,'Mutual (Ley 16.744 + SANNA)',pct(p.mutual),clp(m.mutual));
  fila(e,'Costo total empresa','Haberes + aportes del empleador',clp(x.costoEmpresa),'info');
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',iniciar);else iniciar();
})(typeof globalThis!=='undefined'?globalThis:this);
