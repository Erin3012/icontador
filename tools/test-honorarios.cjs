// Lectura del informe mensual de boletas de honorarios recibidas del SII: node tools/test-honorarios.cjs
const assert=require('node:assert/strict');
const {parseInforme}=require('../assets/honorarios-import.js');
const fila=(n,fecha,estado,anul,rut,nombre,soc,b,r,p)=>`<tr>\n<td>${n}</td>\n<td>${fecha}</td>\n<td>${estado} </td>\n<td>${anul} </td>\n<td>${rut}</td>\n<td>${nombre}</td>\n<td>${soc}</td>\n<td><div align="right">${b}</div></td>\n<td><div align="right">${r}</div></td>\n<td><div align="right">${p}</div></td>\n</tr>`;
// Misma estructura que informeMensualREC.xls (HTML con entidades, ISO-8859-1 ya decodificado).
const informe=`<table border="1" cellspacing="0" cellpadding="2">
<tr><td colspan="10">Contribuyente: EMPRESA DE PRUEBA LIMITADA<br>
	RUT : 76000000-0<p></p>
	Informe correpondiente al mes  09 del año 2026</td></tr>
	<tr class="normal">
	    <td colspan="4" bgcolor="#CCCCCC" ><div align="center"><strong>Boleta</strong></div></td>
	    <td colspan="3" bgcolor="#CCCCCC" ><div align="center"><strong>Emisor</strong></div></td>
	    <td colspan="3" bgcolor="#CCCCCC" ><div align="center"><strong>Honorarios</strong></div></td>
	</tr>
	<tr class="normal">
	<td><div align="center"><strong>N&deg;</strong></div></td><td><strong>Fecha</strong></td><td><strong>Estado</strong></td><td><strong>Fecha Anulaci&oacute;n</strong></td>
	<td><strong>Rut</strong></td><td><strong>Nombre o Raz&oacute;n&nbsp;Social</strong></td><td><strong>Soc. Prof.</strong></td>
	<td><strong>Brutos</strong></td><td><strong>Retenido</strong></td><td><strong>Pagado</strong></td>
	</tr>
${fila(58,'30/09/2026','VIGENTE','','11111111-1','PERSONA UNO','NO',943953,143953,800000)}${fila(59,'30/09/2026','ANULADA','30/09/2026','22222222-2','PERSONA N&Uacute;&Ntilde;EZ','SI',100000,15250,84750)}<tr>
<td colspan="7" bgcolor="#CCCCCC"><strong>Totales* :</strong></td>
<td>943953</td><td>143953</td><td>800000</td>
</tr>
</table>
(*) Los valores totales no consideran los montos de las boletas anuladas.`;
const p=parseInforme(informe,'informeMensualREC.xls');
assert.equal(p.kind,'recibidas');assert.equal(p.rutEmpresa,'76000000-0');assert.equal(p.period,'2026-09');
assert.equal(p.boletas.length,2);
assert.deepEqual(p.boletas[0],{numero:'58',fecha:'30/09/2026',estado:'VIGENTE',fechaAnulacion:'',rut:'11111111-1',nombre:'PERSONA UNO',socProf:false,bruto:943953,retenido:143953,pagado:800000});
assert.equal(p.boletas[1].nombre,'PERSONA NÚÑEZ');assert.equal(p.boletas[1].socProf,true);assert.equal(p.boletas[1].fechaAnulacion,'30/09/2026');
// Igual que el SII: las anuladas no suman.
assert.deepEqual(p.totales,{boletas:2,anuladas:1,bruto:943953,retenido:143953,pagado:800000});
assert.equal(parseInforme(informe.replace('<strong>Emisor</strong>','<strong>Receptor</strong>'),'x.xls').kind,'emitidas');
assert.throws(()=>parseInforme('Nro;Tipo Doc\n1;33','RCV.csv'),/No se reconoce/);
assert.throws(()=>parseInforme('<table><tr><td>Otra cosa</td></tr></table>','x.xls'),/No se reconoce/);
console.log('Honorarios: informe mensual del SII leído (boletas, anuladas, RUT y período)');
