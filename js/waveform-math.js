/* Fixed-size peaks of decoded samples, including both stereo channels. */
(() => {
 function peaks(channels,bins=512){
  if(!Array.isArray(channels)||!channels.length||!channels[0].length||bins!==512)throw new Error('Audio sin muestras.');
  const length=channels[0].length;if(channels.some(c=>c.length!==length))throw new Error('Canales incompletos.');const out=[];
  for(let i=0;i<bins;i++){const start=Math.floor(i*length/bins),end=Math.max(start+1,Math.floor((i+1)*length/bins));let peak=0;for(const samples of channels)for(let k=start;k<Math.min(end,length);k++)if(Number.isFinite(samples[k]))peak=Math.max(peak,Math.abs(samples[k]));out.push(Math.min(1000,Math.round(peak*1000)));}
  return out;
 }
 window.MusicMadWaveMath={peaks};
})();
