const canvas=document.querySelector('canvas'),context=canvas.getContext('2d');
let frame=0;
function draw(){context.fillStyle='#190d22';context.fillRect(0,0,640,360);context.fillStyle='#d5ff38';context.fillRect(40+(frame++%120),90,200,100);context.fillStyle='#fff';context.font='32px sans-serif';context.fillText('MusicMad QA',40,300);requestAnimationFrame(draw);}draw();
document.getElementById('record').addEventListener('click',()=>{
 const status=document.getElementById('status');
 if(!window.MediaRecorder || !MediaRecorder.isTypeSupported('video/webm;codecs=vp8')){status.textContent='Este navegador no admite la prueba VP8.';return;}
 const stream=canvas.captureStream(24),recorder=new MediaRecorder(stream,{mimeType:'video/webm;codecs=vp8'}),parts=[];
 recorder.ondataavailable=event=>{if(event.data.size)parts.push(event.data);};
 recorder.onstop=()=>{stream.getTracks().forEach(track=>track.stop());const link=document.getElementById('download');link.href=URL.createObjectURL(new Blob(parts,{type:'video/webm'}));link.hidden=false;status.textContent='Video ficticio listo.';};
 recorder.start();status.textContent='Generando video…';setTimeout(()=>recorder.stop(),2200);
});
