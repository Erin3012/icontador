const assert=require('node:assert/strict');
const c=require('../assets/contabilidad.js');
assert.deepEqual(c.totales({lineas:[{debe:'1000',haber:0},{debe:0,haber:'1000'}]}),{debe:1000,haber:1000,diferencia:0});
assert.equal(c.totales({lineas:[{debe:500},{haber:300}]}).diferencia,200);
assert.equal(c.fechaCorta('2026-10-02'),'02/10/2026');
assert.equal(c.formato(1234567),'1.234.567');
const libro={asientos:[{fecha:'2026-10-01',tipoNombre:'Ingreso',numero:1,glosa:'Venta; contado',lineas:[{cuenta:'1.1.01',nombre:'Caja',glosa:'',debe:119000,haber:0},{cuenta:'4.1.01',nombre:'Ventas',glosa:'',debe:0,haber:119000}]}],debe:119000,haber:119000};
const texto=c.csv(c.filasDiario(libro));
assert(texto.startsWith('﻿Fecha;Tipo'));assert(texto.includes('"Venta; contado"'));assert(texto.trim().endsWith('Total;119000;119000'));
const mayor=c.csv(c.filasMayor([{codigo:'1.1.01',nombre:'Caja',saldoAnterior:0,movimientos:[{fecha:'2026-10-01',tipoNombre:'Ingreso',numero:1,glosa:'Venta',debe:119000,haber:0,saldo:119000}],debe:119000,haber:0,saldo:119000}]));
assert.equal(mayor.split('\r\n').length,4);
const balance=c.filasBalance({cuentas:[{codigo:'1.1.01',nombre:'Caja',debitos:100,creditos:0,deudor:100,acreedor:0,activo:100,pasivo:0,perdida:0,ganancia:0},{codigo:'4.1.01',nombre:'Ventas',debitos:0,creditos:100,deudor:0,acreedor:100,activo:0,pasivo:0,perdida:0,ganancia:100}],
 totales:{debitos:100,creditos:100,deudor:100,acreedor:100,activo:100,pasivo:0,perdida:0,ganancia:100},resultado:100,ajuste:{activo:0,pasivo:100,perdida:100,ganancia:0},sumasIguales:{activo:100,pasivo:100,perdida:100,ganancia:100}});
assert.equal(balance.length,6);assert.equal(balance[4][1],'Utilidad del ejercicio');assert.deepEqual(balance[5].slice(6),[100,100,100,100]);
const eerr=c.filasResultado({ingresos:[{codigo:'4.1.01',nombre:'Ventas',monto:100}],gastos:[],totalIngresos:100,totalGastos:0,resultado:-5});
assert.equal(eerr.at(-1)[1],'Pérdida del ejercicio');
const lc=c.filasLibroRcv({libro:'compras',docs:[{periodo:'2026-09',fecha:'03/09/2026',tipo:61,folio:'9',rut:'1-9',razon:'P',exento:0,neto:50,iva:10,ivaNoRec:0,ivaUsoComun:0,otros:0,total:60,signo:-1}],totales:{documentos:1,exento:0,neto:-50,iva:-10,ivaNoRec:0,ivaUsoComun:0,otros:0,total:-60}});
assert.equal(lc[0][5],'Proveedor');assert.equal(lc[1][2],'Nota de crédito electrónica');assert.equal(lc[1][7],-50);assert.equal(lc[0].length,lc[1].length);assert.equal(lc[2].at(-1),-60);
assert.equal(c.filasLibroRcv({libro:'ventas',docs:[],totales:{documentos:0,exento:0,neto:0,iva:0,ivaRetenido:0,otros:0,total:0}})[0].includes('IVA retenido'),true);
// Excel: ZIP válido (CRC conocido) con la cabecera arriba, números como números y totales en negrita.
assert.equal(c.crc32(new TextEncoder().encode('hello')),0x3610a686);
const libroXlsx=Buffer.from(c.xlsx({hoja:'Libro Diario',cabecera:['Libro Diario','Empresa & Cía'],filas:[['Glosa','Debe'],['Venta <contado>',119000],['Total',119000]],destacar:f=>f[0]==='Total'}));
assert.equal(libroXlsx.readUInt32LE(0),0x04034b50);assert.equal(libroXlsx.readUInt32LE(libroXlsx.length-22),0x06054b50);
const hojaXlsx=libroXlsx.toString('utf8');
for(const parte of ['[Content_Types].xml','xl/workbook.xml','xl/worksheets/sheet1.xml','xl/styles.xml'])assert(hojaXlsx.includes(parte),parte);
assert(hojaXlsx.includes('Empresa &amp; Cía'));assert(hojaXlsx.includes('Venta &lt;contado&gt;'));
assert(hojaXlsx.includes('<c r="B5" s="2"><v>119000</v></c>'));assert(hojaXlsx.includes('<c r="B6" s="3"><v>119000</v></c>'));
assert(hojaXlsx.includes('<pane ySplit="4"'));
console.log('contabilidad.js: pruebas superadas');
