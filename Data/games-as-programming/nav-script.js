function initNav(){
  const sects=document.querySelectorAll('section[id],.hero[id]');
  const links=document.querySelectorAll('#main-nav a');
  const obs=new IntersectionObserver(es=>{es.forEach(e=>{if(e.isIntersecting){links.forEach(l=>l.classList.remove('active'));const a=document.querySelector(`#main-nav a[href="#${e.target.id}"]`);if(a)a.classList.add('active');}})},{rootMargin:'-25% 0px -70% 0px'});
  sects.forEach(s=>obs.observe(s));
  links.forEach(l=>l.addEventListener('click',e=>{e.preventDefault();const t=document.querySelector(l.getAttribute('href'));if(t)t.scrollIntoView({behavior:'smooth'});}));
}
initNav();
