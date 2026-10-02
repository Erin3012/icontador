const {sanitize}=require('./sanitize.js');
let input='';process.stdin.setEncoding('utf8');process.stdin.on('data',s=>input+=s);process.stdin.on('end',()=>{const {html,assets,url}=JSON.parse(input);process.stdout.write(sanitize(html,assets,url));});
