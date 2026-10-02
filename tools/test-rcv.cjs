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
