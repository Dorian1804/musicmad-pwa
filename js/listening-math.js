(() => {
 function advance(previous,current,wallMs,rate,seeking=false){
  if(seeking||![previous,current,wallMs,rate].every(Number.isFinite)||wallMs<=0||wallMs>5000||rate<.5||rate>2)return 0;
  const delta=current-previous;if(delta<=0||delta>wallMs/1000*rate+.35)return 0;
  return Math.min(wallMs,delta*1000);
 }
 window.MusicMadListeningMath={advance};
})();
