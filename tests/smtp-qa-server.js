const tls=require('node:tls'),fs=require('node:fs'),crypto=require('node:crypto');
const {musicmadQaRoot}=require('./paths.cjs');
const root=musicmadQaRoot();let deliveries=0;
const server=tls.createServer({key:fs.readFileSync(root+'/smtp-qa.key'),cert:fs.readFileSync(root+'/smtp-qa.crt')},socket=>{
 socket.setEncoding('utf8');socket.write('220 MusicMad QA SMTP\r\n');let buffer='',inData=false,data='',auth=0;
 socket.on('data',chunk=>{buffer+=chunk;let index;while((index=buffer.indexOf('\r\n'))>=0){const line=buffer.slice(0,index);buffer=buffer.slice(index+2);
  if(inData){if(line==='.') {inData=false;deliveries++;fs.writeFileSync(root+'/smtp-qa-result.json',JSON.stringify({deliveries,sha256:crypto.createHash('sha256').update(data).digest('hex'),utf8:data.includes('UTF-8')}));socket.write('250 QA accepted\r\n');}else data+=line+'\r\n';continue;}
  if(auth){auth++;socket.write(auth===2?'334 UGFzc3dvcmQ6\r\n':'235 QA authentication accepted\r\n');if(auth===3)auth=0;continue;}
  if(/^EHLO|^HELO/i.test(line))socket.write('250-localhost\r\n250-SIZE 65536\r\n250 AUTH LOGIN PLAIN\r\n');
  else if(/^AUTH LOGIN/i.test(line)){auth=1;socket.write('334 VXNlcm5hbWU6\r\n');}
  else if(/^AUTH PLAIN/i.test(line))socket.write('235 QA authentication accepted\r\n');
  else if(/^MAIL FROM:|^RCPT TO:|^RSET/i.test(line))socket.write('250 OK\r\n');
  else if(/^DATA/i.test(line)){inData=true;data='';socket.write('354 End with dot\r\n');}
  else if(/^QUIT/i.test(line)){socket.end('221 Bye\r\n');}
  else socket.write('250 OK\r\n');
 }});socket.on('error',()=>{});
});server.listen(2465,'127.0.0.1',()=>process.stdout.write('SMTP TLS QA en127.0.0.1:2465; no entrega externa.\n'));
