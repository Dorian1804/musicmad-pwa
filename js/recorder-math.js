/* A bounded mono PCM WAV encoder; no transport or microphone access. */
(() => {
 function wav(channels, rate=16000) {
  if(!Array.isArray(channels)||!channels.length||channels.length>2||!Number.isInteger(rate)||rate<8000||rate>48000)throw new Error('Audio inválido.');
  const length=channels[0].length;
  if(!length||length>rate*90||channels.some(c=>!(c instanceof Float32Array)||c.length!==length))throw new Error('La toma supera 90 segundos o está incompleta.');
  const result=new ArrayBuffer(44+length*2),v=new DataView(result);
  const text=(offset,s)=>{for(let i=0;i<s.length;i++)v.setUint8(offset+i,s.charCodeAt(i));};
  text(0,'RIFF');v.setUint32(4,36+length*2,true);text(8,'WAVE');text(12,'fmt ');v.setUint32(16,16,true);v.setUint16(20,1,true);v.setUint16(22,1,true);v.setUint32(24,rate,true);v.setUint32(28,rate*2,true);v.setUint16(32,2,true);v.setUint16(34,16,true);text(36,'data');v.setUint32(40,length*2,true);
  for(let i=0;i<length;i++){let n=0;for(const channel of channels){if(!Number.isFinite(channel[i]))throw new Error('Audio inválido.');n+=channel[i]/channels.length;}n=Math.min(1,Math.max(-1,n));v.setInt16(44+i*2,Math.round(n*(n<0?32768:32767)),true);}
  return result;
 }
 window.MusicMadRecorderMath={wav};
})();
