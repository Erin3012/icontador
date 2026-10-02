const assert=require('node:assert/strict');const {calcular,impuestoUnico,PARAMETROS:p}=require('../assets/liquidacion.js');
// Tabla mensual del SII para octubre 2026: límite superior y cantidad a rebajar de cada tramo.
const sii=[[974038.50,0],[2164530,38961.54],[3607550,125542.74],[5050570,323957.99],[6493590,803762.14],[8658120,1284287.80],[22366810,1682561.32],[Infinity,2800901.82]];
for(const [hasta,rebaja] of sii){const t=impuestoUnico(Math.min(hasta,1e9),p.utm,p.tramos);assert.equal(Math.round(t.hasta*100)/100,hasta);assert.equal(Math.round(t.rebaja*100)/100,rebaja);}
let x=calcular({sueldoBase:1000000,gratificacion:true,afp:'Habitat',salud:'fonasa',contrato:'indefinido'});
assert.deepEqual([x.gratificacion,x.imponible,x.afp,x.saludLegal,x.afc,x.baseTributable,x.impuesto,x.liquido],[219115,1219115,137394,85338,7315,989068,601,988467]);
x=calcular({sueldoBase:5000000,afp:'Habitat',salud:'fonasa',contrato:'indefinido'});
assert.deepEqual([x.baseAfp,x.afp,x.saludLegal,x.afc,x.baseTributable,x.impuesto],[3695148,416443,258660,30000,4294897,255853]);
x=calcular({sueldoBase:553553,afp:'Modelo',salud:'fonasa',contrato:'plazoFijo'});
assert.deepEqual([x.afp,x.saludLegal,x.afc,x.impuesto,x.liquido],[58566,38749,0,0,456238]);
x=calcular({sueldoBase:2000000,afp:'Uno',salud:'isapre',planUf:5,contrato:'indefinido',noImponibles:50000,otrosDescuentos:100000});
assert.equal(x.salud,205286);assert.equal(x.adicionalIsapre,65286);assert.equal(x.baseTributable,2000000-209200-140000-12000);
assert.equal(x.liquido,2050000-x.afp-x.salud-x.afc-x.impuesto-100000);
console.log('Calculadora de liquidación: casos verificados');
