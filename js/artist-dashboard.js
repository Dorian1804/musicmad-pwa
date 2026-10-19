(() => {
 let chart=null;
 function clear(){chart?.destroy();chart=null;}
 function render(){clear();const root=document.querySelector('[data-artist-chart]');if(!root||!window.Chart)return;
  try{const points=JSON.parse(root.dataset.points);if(!Array.isArray(points)||points.length>90||points.some(p=>!/^\d{4}-\d{2}-\d{2}$/.test(p.fecha)||!Number.isInteger(p.escuchas)||p.escuchas<0))return;
   const style=getComputedStyle(root),dark=document.documentElement.dataset.theme==='dark',text=style.color;
   chart=new Chart(root.querySelector('canvas'),{type:'bar',data:{labels:points.map(p=>p.fecha.slice(5).split('-').reverse().join('/')),datasets:[{label:'Escuchas confirmadas',data:points.map(p=>p.escuchas),backgroundColor:'#82a652',hoverBackgroundColor:'#c17d48',borderRadius:2,maxBarThickness:30}]},options:{responsive:true,maintainAspectRatio:false,animation:false,plugins:{legend:{display:false},tooltip:{displayColors:false}},scales:{x:{grid:{display:false},ticks:{color:text,maxTicksLimit:6,maxRotation:0}},y:{beginAtZero:true,ticks:{precision:0,color:text},grid:{color:dark?'#ffffff18':'#00000012'}}}}});
  }catch(_){/* The accessible table remains available if the chart cannot render. */}
 }
 document.addEventListener('musicmad:beforepage',clear);document.addEventListener('musicmad:session',clear);document.addEventListener('musicmad:page',render);document.addEventListener('musicmad:theme',render);render();
})();
