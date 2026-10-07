const fs=require('node:fs');const path=require('node:path');const assert=require('node:assert/strict');
const {parseRcv,buildBooks}=require('../assets/rcv-import.js');
const dir=path.resolve(__dirname,'..','samples');
const parsed=fs.readdirSync(dir).filter(f=>/^RCV_.*\.csv$/.test(f)).map(f=>parseRcv(fs.readFileSync(path.join(dir,f),'utf8'),f));
const books=buildBooks(parsed);
assert.equal(books.period,'2026-09');
assert.equal(books.compras.docs.length,5);assert.equal(books.ventas.docs.length,4);
// Compras: 190.000 + 47.500 + 15.966 - 9.500 (nota de crédito) = 243.966
assert.equal(books.compras.totales.iva,243966);assert.equal(books.compras.totales.neto,1284034);assert.equal(books.compras.totales.exento,120000);
// Ventas: 380.000 + 142.500 - 19.000 (nota de crédito) = 503.500
assert.equal(books.ventas.totales.iva,503500);assert.equal(books.ventas.totales.total,3453500);
assert.equal(books.iva.debito,503500);assert.equal(books.iva.credito,243966);assert.equal(books.iva.ivaPagar,259534);assert.equal(books.iva.remanente,0);
assert.deepEqual(books.compras.resumen.map(r=>r.tipo),[33,34,61]);
// Archivo con separador coma, BOM, comillas y montos con puntos de miles.
const alt=parseRcv('﻿"Nro","Tipo Doc","Tipo Compra","RUT Proveedor","Razon Social","Folio","Fecha Docto","Monto Exento","Monto Neto","Monto IVA Recuperable","Monto Total"\n1,33,Del Giro,1-9,"EMPRESA, CON COMA",5,01/08/2026,0,"1.000","190","1.190"\n','sin-periodo.csv');
assert.equal(alt.kind,'compras');assert.equal(alt.period,'2026-08');assert.equal(alt.docs[0].razon,'EMPRESA, CON COMA');assert.equal(alt.docs[0].neto,1000);
assert.equal(buildBooks([alt]).iva.remanente,190);
assert.throws(()=>parseRcv('a;b;c\n1;2;3','x.csv'),/No se reconoce/);
console.log('RCV: libros e IVA verificados ('+books.compras.docs.length+' compras, '+books.ventas.docs.length+' ventas, IVA a pagar '+books.iva.ivaPagar+')');
// Formato completo del SII (2026): IVA no recuperable con código, otro impuesto con código y nota de crédito.
const H='Nro;Tipo Doc;Tipo Compra;RUT Proveedor;Razon Social;Folio;Fecha Docto;Fecha Recepcion;Fecha Acuse;Monto Exento;Monto Neto;Monto IVA Recuperable;Monto Iva No Recuperable;Codigo IVA No Rec.;Monto Total;Monto Neto Activo Fijo;IVA Activo Fijo;IVA uso Comun;Impto. Sin Derecho a Credito;IVA No Retenido;Tabacos Puros;Tabacos Cigarrillos;Tabacos Elaborados;NCE o NDE sobre Fact. de Compra;Codigo Otro Impuesto;Valor Otro Impuesto;Tasa Otro Impuesto';
const full=parseRcv([H,
 '1;33;IVA no Recuperable;76000001-1;BENCINERA;100;25/09/2026;25/09/2026 12:23:03;;0;38666;;7347;9;49655;;;;3642;0;;;;0;35;3642;0;',
 '2;33;Del Giro;76000002-2;FERRETERIA;200;04/09/2026;04/09/2026 15:24:58;;0;100000;19000;;;119000;100000;19000;;;0;;;;0;;;;',
 '3;33;IVA no Recuperable;76000003-3;SUPERMERCADO;300;13/09/2026;13/09/2026 15:34:40;;0;19432;;3692;9;23460;;;;;0;;;;0;271;336;18;',
 '4;61;IVA no Recuperable;76000001-1;BENCINERA;7;10/09/2026;10/09/2026 16:37:04;;0;27951;;5311;9;39080;;;;5818;0;;;;0;35;5818;0;',
 '5;34;Del Giro;76000004-4;INVERSIONES;4248;25/09/2026;24/09/2026 12:04:09;;176100;0;0;;;176100;;;;;0;;;;0;;;;'].join('\n'),'RCV_COMPRA_REGISTRO_76000000-0_202609.csv');
assert.equal(full.rutEmpresa,'76000000-0');assert.equal(full.period,'2026-09');
assert.deepEqual([full.docs[0].ivaNoRec,full.docs[0].ivaNoRecCodigo,full.docs[0].otroImpCodigo,full.docs[0].otros,full.docs[0].impSinCredito,full.docs[0].fechaRecepcion],[7347,'9','35',3642,3642,'25/09/2026 12:23:03']);
const fb=buildBooks([full]);
assert.equal(fb.compras.totales.iva,19000);assert.equal(fb.compras.totales.ivaNoRec,7347+3692-5311);assert.equal(fb.compras.totales.ivaActivoFijo,19000);
assert.deepEqual(fb.compras.otrosImpuestos.map(o=>[o.codigo,o.monto]),[['35',3642-5818],['271',336]]);
assert.deepEqual(fb.compras.ivaNoRecuperable.map(o=>[o.codigo,o.documentos,o.monto]),[['9',3,7347+3692-5311]]);
assert.equal(fb.iva.credito,19000);assert.equal(fb.iva.ivaActivoFijo,19000);
const venta=parseRcv('Nro;Tipo Doc;Tipo Venta;Rut cliente;Razon Social;Folio;Fecha Docto;Fecha Recepcion;Monto Exento;Monto Neto;Monto IVA;Monto total;IVA Retenido Total;Tipo Docto. Referencia;Folio Docto. Referencia;Codigo Otro Imp.;Valor Otro Imp.;Tasa Otro Imp.\n1;61;Del Giro;76000009-9;CLIENTE;12;16/09/2026;16/09/2026 10:27:40;0;1000;190;1190;0;33;35;;;\n','RCV_VENTA_76000000-0_202609.csv');
assert.deepEqual([venta.kind,venta.docs[0].refTipo,venta.docs[0].refFolio],['ventas','33','35']);
assert.throws(()=>parseRcv(H+'\n','RCV_COMPRA_PENDIENTE_76000000-0_202609.csv'),/pendientes/);
assert.throws(()=>parseRcv('<table><tr><td>Honorarios</td></tr></table>','informeMensualREC.xls'),/Honorarios/);
console.log('RCV: formato completo del SII (códigos de IVA no recuperable, otros impuestos, activo fijo, referencias) verificado');
