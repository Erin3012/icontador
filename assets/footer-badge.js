// Pie de página común a todas las pantallas: badge "Destroy this website" de Sprite Fusion apuntando a qlc.cl.
// Se arma con JS (sin estilos inline en el HTML) para que funcione con la CSP de cada vista.
(function(){
 const SITIO='https://qlc.cl';
 function agregarBadgeFooter(){
  if(document.querySelector('.icontador-footer'))return;
  const pie=document.createElement('footer');pie.className='icontador-footer';
  Object.assign(pie.style,{clear:'both',display:'flex',justifyContent:'center',padding:'16px 0 24px'});
  const enlace=document.createElement('a');
  enlace.href='https://destroy.spritefusion.com/?'+new URLSearchParams({from:'badge',url:SITIO});
  enlace.target='_blank';enlace.rel='noopener';
  const img=document.createElement('img');
  img.src='https://destroy.spritefusion.com/badge.svg';img.alt='Destroy this website';img.width=180;img.height=40;
  enlace.append(img);pie.append(enlace);document.body.append(pie);
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',agregarBadgeFooter);else agregarBadgeFooter();
})();
