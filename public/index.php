<?php
declare(strict_types=1);

const ROOT = __DIR__ . '/..';
$appName = getenv('FEEDBACK_APP_NAME') ?: 'Feedback Board';
$dbPath = getenv('FEEDBACK_DB_PATH') ?: ROOT . '/storage/feedback.sqlite';
if (!str_starts_with($dbPath, '/')) $dbPath = ROOT . '/' . $dbPath;
if (!is_dir(dirname($dbPath))) mkdir(dirname($dbPath), 0775, true);

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('feedback_board');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");

$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA journal_mode = WAL');
$pdo->exec("CREATE TABLE IF NOT EXISTS feedback (
 id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, body TEXT NOT NULL,
 category TEXT NOT NULL DEFAULT 'Feature', status TEXT NOT NULL DEFAULT 'Under Review',
 author TEXT NOT NULL DEFAULT 'Community', votes INTEGER NOT NULL DEFAULT 0,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS comments (
 id INTEGER PRIMARY KEY AUTOINCREMENT, feedback_id INTEGER NOT NULL REFERENCES feedback(id) ON DELETE CASCADE,
 author TEXT NOT NULL, body TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS votes (
 id INTEGER PRIMARY KEY AUTOINCREMENT, feedback_id INTEGER NOT NULL REFERENCES feedback(id) ON DELETE CASCADE,
 voter_key TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(feedback_id, voter_key)
)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_feedback_status ON feedback(status)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_comments_feedback ON comments(feedback_id)");

function e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function verifyCsrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'] ?? '')) { http_response_code(419); exit('Session expired. Go back and try again.'); }
}
function redirect(string $to): never { header('Location: ' . $to, true, 303); exit; }
function admin(): bool { return !empty($_SESSION['is_admin']); }
function notice(string $message, string $kind='success'): void { $_SESSION['notice'] = [$message,$kind]; }
function layout(string $title, string $content, string $appName): void {
    $notice = $_SESSION['notice'] ?? null; unset($_SESSION['notice']);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#f7f8f5"><title>'.e($title).' · '.e($appName).'</title><link rel="stylesheet" href="/assets/styles.css"></head><body>';
    echo '<header class="topbar"><a class="brand" href="/"><span class="brand-mark">F</span><span>'.e($appName).'</span></a><nav><a href="/">Feedback</a><a href="/roadmap">Roadmap</a><a class="nav-cta" href="/submit">Share an idea <span>↗</span></a></nav></header><main class="shell">';
    if ($notice) echo '<div class="notice '.e($notice[1]).'">'.e($notice[0]).'</div>';
    echo $content.'</main><footer><span>Built for better products.</span><a href="/admin/login">Admin</a></footer></body></html>';
}
function hidden(string $name,string $value): string { return '<input type="hidden" name="'.e($name).'" value="'.e($value).'">'; }
function statusClass(string $status): string { return match($status) { 'Planned'=>'planned','In Progress'=>'progress','Completed'=>'completed','Declined'=>'declined',default=>'review' }; }
function feedbackCard(array $item): string {
    $body=(string)$item['body']; $excerpt=substr($body,0,170);
    return '<article class="feedback-card"><a class="vote-box" href="/feedback/'.(int)$item['id'].'"><strong>'.(int)$item['votes'].'</strong><span>votes</span></a><div class="card-copy"><div class="card-meta"><span class="tag">'.e($item['category']).'</span><span>·</span><span>'.e(date('M j, Y',strtotime($item['created_at']))).'</span></div><h3><a href="/feedback/'.(int)$item['id'].'">'.e($item['title']).'</a></h3><p>'.e($excerpt).(strlen($body)>170?'…':'').'</p><div class="card-bottom"><span class="status '.statusClass($item['status']).'">'.e($item['status']).'</span><span>'.(int)($item['comment_count']??0).' comments →</span></div></div></article>';
}
$path=rtrim(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/','/')?:'/';
$method=$_SERVER['REQUEST_METHOD']??'GET';

if($path==='/api/feedback'){
    header('Content-Type: application/json; charset=utf-8');
    $rows=$pdo->query("SELECT f.id,f.title,f.body,f.category,f.status,f.author,f.votes,f.created_at,COUNT(c.id) AS comments FROM feedback f LEFT JOIN comments c ON c.feedback_id=f.id GROUP BY f.id ORDER BY f.votes DESC,f.created_at DESC")->fetchAll();
    echo json_encode(['data'=>$rows],JSON_UNESCAPED_SLASHES);exit;
}

if($path==='/admin/login'&&$method==='POST'){
    verifyCsrf();$configured=getenv('FEEDBACK_ADMIN_PASSWORD')?:'';$candidate=(string)($_POST['password']??'');
    $hashed=str_starts_with($configured,'$2y$')||str_starts_with($configured,'$argon2');
    $valid=$configured!==''&&($hashed?password_verify($candidate,$configured):hash_equals($configured,$candidate));
    if($valid){session_regenerate_id(true);$_SESSION['is_admin']=true;notice('You are signed in.');redirect('/admin');}
    usleep(250000);notice('Incorrect password. Check your credentials and try again.','error');redirect('/admin/login');
}
if($path==='/admin/logout'&&$method==='POST'){verifyCsrf();$_SESSION=[];session_regenerate_id(true);notice('You have been signed out.');redirect('/');}

if($path==='/submit'&&$method==='POST'){
    verifyCsrf();$title=trim((string)($_POST['title']??''));$body=trim((string)($_POST['body']??''));$author=trim((string)($_POST['author']??''))?:'Community';$category=(string)($_POST['category']??'Feature');
    if(strlen($title)<5||strlen($title)>100||strlen($body)<15||strlen($body)>3000||strlen($author)>50||!in_array($category,['Feature','Improvement','Bug','Other'],true)){notice('Please check the form: title must be 5–100 characters and details 15–3,000 characters.','error');redirect('/submit');}
    $s=$pdo->prepare('INSERT INTO feedback(title,body,category,author) VALUES(?,?,?,?)');$s->execute([$title,$body,$category,$author]);notice('Your idea is live. Thanks for helping shape the product.');redirect('/feedback/'.$pdo->lastInsertId());
}

if(preg_match('#^/feedback/(\d+)$#',$path,$m)){
    $id=(int)$m[1];$s=$pdo->prepare('SELECT * FROM feedback WHERE id=?');$s->execute([$id]);$item=$s->fetch();
    if(!$item){http_response_code(404);layout('Not found','<section class="empty"><h1>Idea not found</h1><p>This suggestion may have been removed.</p><a class="button" href="/">Back to feedback</a></section>',$appName);exit;}
    if($method==='POST'&&($_POST['action']??'')==='vote'){
        verifyCsrf();$key=hash('sha256',session_id());
        try{$pdo->beginTransaction();$s=$pdo->prepare('INSERT INTO votes(feedback_id,voter_key) VALUES(?,?)');$s->execute([$id,$key]);$pdo->prepare('UPDATE feedback SET votes=votes+1,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$id]);$pdo->commit();notice('Your vote has been counted.');}
        catch(PDOException $ex){if($pdo->inTransaction())$pdo->rollBack();notice('You have already voted for this idea from this browser.','error');}
        redirect('/feedback/'.$id);
    }
    if($method==='POST'&&($_POST['action']??'')==='comment'){
        verifyCsrf();$author=trim((string)($_POST['author']??''))?:'Community';$body=trim((string)($_POST['body']??''));$last=(int)($_SESSION['last_comment']??0);
        if(time()-$last<20||strlen($body)<2||strlen($body)>1200||strlen($author)>50){notice('Please wait a moment and check your comment (2–1,200 characters).','error');redirect('/feedback/'.$id);}
        $s=$pdo->prepare('INSERT INTO comments(feedback_id,author,body) VALUES(?,?,?)');$s->execute([$id,$author,$body]);$_SESSION['last_comment']=time();notice('Comment added.');redirect('/feedback/'.$id);
    }
    $s=$pdo->prepare('SELECT * FROM comments WHERE feedback_id=? ORDER BY created_at ASC');$s->execute([$id]);$comments=$s->fetchAll();
    $content='<a class="back-link" href="/">← All feedback</a><section class="detail-head"><div class="eyebrow">'.e($item['category']).' · '.e(date('M j, Y',strtotime($item['created_at']))).'</div><h1>'.e($item['title']).'</h1><p class="lead">'.nl2br(e($item['body'])).'</p><div class="detail-actions"><form method="post">'.hidden('_csrf',csrf()).hidden('action','vote').'<button class="button" type="submit">↑ Upvote · '.(int)$item['votes'].'</button></form><span class="status '.statusClass($item['status']).'">'.e($item['status']).'</span><span class="muted">Posted by '.e($item['author']).'</span></div></section>';
    $content.='<section class="comments"><div class="section-heading"><div><span class="eyebrow">THE DISCUSSION</span><h2>'.count($comments).' comments</h2></div></div><form class="comment-form panel" method="post">'.hidden('_csrf',csrf()).hidden('action','comment').'<label>Your name <input name="author" maxlength="50" placeholder="Community"></label><label>Join the conversation <textarea name="body" required minlength="2" maxlength="1200" rows="3" placeholder="Share your thoughts…"></textarea></label><button class="button" type="submit">Post comment ↗</button></form>';
    foreach($comments as $c)$content.='<article class="comment"><div class="avatar">'.e(strtoupper(substr($c['author'],0,1))).'</div><div><div class="comment-meta"><strong>'.e($c['author']).'</strong><span>'.e(date('M j · H:i',strtotime($c['created_at']))).'</span></div><p>'.nl2br(e($c['body'])).'</p></div></article>';
    $content.='</section>';layout($item['title'],$content,$appName);exit;
}

if($path==='/admin/login'){
    $content='<section class="auth-wrap"><div class="eyebrow">PRIVATE WORKSPACE</div><h1>Welcome back.</h1><p class="lead">Sign in to manage community feedback.</p><form class="panel form-stack" method="post">'.hidden('_csrf',csrf()).'<label>Admin password<input type="password" name="password" required autocomplete="current-password"></label><button class="button" type="submit">Sign in ↗</button></form><a class="back-link" href="/">← Back to board</a></section>';layout('Admin sign in',$content,$appName);exit;
}
if(str_starts_with($path,'/admin')){
    if(!admin())redirect('/admin/login');
    if($path==='/admin/export'){
        header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="feedback-export.csv"');$out=fopen('php://output','w');fputcsv($out,['ID','Title','Details','Category','Status','Author','Votes','Created']);
        foreach($pdo->query('SELECT id,title,body,category,status,author,votes,created_at FROM feedback ORDER BY created_at DESC') as $row)fputcsv($out,$row);fclose($out);exit;
    }
    if($path==='/admin'&&$method==='POST'){
        verifyCsrf();$action=$_POST['action']??'';
        if($action==='update'){$id=(int)($_POST['id']??0);$status=(string)($_POST['status']??'Under Review');if(in_array($status,['Under Review','Planned','In Progress','Completed','Declined'],true)){$s=$pdo->prepare('UPDATE feedback SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');$s->execute([$status,$id]);notice('Feedback status updated.');}}
        elseif($action==='delete'){$s=$pdo->prepare('DELETE FROM feedback WHERE id=?');$s->execute([(int)$_POST['id']]);notice('Feedback item deleted.');}
        elseif($action==='delete_comment'){$s=$pdo->prepare('DELETE FROM comments WHERE id=?');$s->execute([(int)$_POST['comment_id']]);notice('Comment removed.');}
        elseif($action==='create'){$title=trim((string)($_POST['title']??''));$body=trim((string)($_POST['body']??''));$status=(string)($_POST['status']??'Planned');if(strlen($title)>=5&&strlen($title)<=100&&strlen($body)>=15&&strlen($body)<=3000&&in_array($status,['Under Review','Planned','In Progress','Completed','Declined'],true)){$s=$pdo->prepare('INSERT INTO feedback(title,body,category,status,author) VALUES(?,?,?,?,?)');$s->execute([$title,$body,'Feature',$status,'Team']);notice('Roadmap item published.');}else notice('Check the title and description fields.','error');}
        redirect('/admin');
    }
    $items=$pdo->query("SELECT f.*,COUNT(c.id) comment_count FROM feedback f LEFT JOIN comments c ON c.feedback_id=f.id GROUP BY f.id ORDER BY f.created_at DESC")->fetchAll();
    $total=(int)$pdo->query('SELECT COUNT(*) FROM feedback')->fetchColumn();$votes=(int)$pdo->query('SELECT COALESCE(SUM(votes),0) FROM feedback')->fetchColumn();$commentTotal=(int)$pdo->query('SELECT COUNT(*) FROM comments')->fetchColumn();
    $content='<div class="admin-head"><div><div class="eyebrow">CONTROL ROOM</div><h1>Community overview</h1><p class="lead">Keep the conversation moving.</p></div><form method="post" action="/admin/logout">'.hidden('_csrf',csrf()).'<button class="button button-light" type="submit">Sign out</button></form></div><div class="stats"><div class="stat"><span>Total ideas</span><strong>'.$total.'</strong></div><div class="stat"><span>Community votes</span><strong>'.$votes.'</strong></div><div class="stat"><span>Comments</span><strong>'.$commentTotal.'</strong></div></div>';
    $content.='<section class="panel admin-create"><div class="section-heading"><div><span class="eyebrow">ROADMAP</span><h2>Publish an update</h2></div></div><form method="post" class="admin-create-grid">'.hidden('_csrf',csrf()).hidden('action','create').'<label>Title<input name="title" required minlength="5" maxlength="100"></label><label>Status<select name="status"><option>Planned</option><option>In Progress</option><option>Completed</option><option>Under Review</option></select></label><label class="wide">Description<textarea name="body" required minlength="15" maxlength="3000" rows="2"></textarea></label><button class="button" type="submit">Publish item ↗</button></form></section><div class="section-heading admin-list-heading"><div><span class="eyebrow">INBOX</span><h2>All feedback</h2></div><a class="text-link" href="/admin/export">Export CSV ↓</a></div>';
    foreach($items as $it){
        $content.='<article class="admin-item"><div class="admin-item-main"><strong>'.e($it['title']).'</strong><p>'.e(substr($it['body'],0,130)).'</p><span class="muted">'.(int)$it['votes'].' votes · '.(int)$it['comment_count'].' comments · by '.e($it['author']).'</span></div><div class="admin-item-actions"><form method="post">'.hidden('_csrf',csrf()).hidden('action','update').hidden('id',(string)$it['id']).'<select name="status" aria-label="Feedback status">';
        foreach(['Under Review','Planned','In Progress','Completed','Declined'] as $st)$content.='<option '.($it['status']===$st?'selected':'').'>'.e($st).'</option>';
        $content.='</select><button class="button button-small" type="submit">Save</button></form><form method="post">'.hidden('_csrf',csrf()).hidden('action','delete').hidden('id',(string)$it['id']).'<button class="danger-link" type="submit">Delete</button></form></div></article>';
        $cs=$pdo->prepare('SELECT * FROM comments WHERE feedback_id=? ORDER BY created_at DESC');$cs->execute([$it['id']]);
        foreach($cs->fetchAll() as $cm)$content.='<div class="admin-comment"><span><strong>'.e($cm['author']).':</strong> '.e($cm['body']).'</span><form method="post">'.hidden('_csrf',csrf()).hidden('action','delete_comment').hidden('comment_id',(string)$cm['id']).'<button class="danger-link" type="submit">Remove</button></form></div>';
    }
    if(!$items)$content.='<div class="empty"><h3>No feedback yet</h3><p>New ideas will appear here.</p></div>';
    layout('Admin dashboard',$content,$appName);exit;
}

if($path==='/submit'){
    $content='<a class="back-link" href="/">← Back to feedback</a><section class="form-page"><div class="eyebrow">YOUR VOICE MATTERS</div><h1>Put an idea<br>into motion.</h1><p class="lead">Tell us what would make this product more useful. Great products are built together.</p><form class="panel form-stack" method="post">'.hidden('_csrf',csrf()).'<label>Idea title<input name="title" required minlength="5" maxlength="100" placeholder="What should we build?"></label><label>Category<select name="category"><option>Feature</option><option>Improvement</option><option>Bug</option><option>Other</option></select></label><label>Your name <span class="optional">optional</span><input name="author" maxlength="50" placeholder="Community"></label><label>Tell us more<textarea name="body" required minlength="15" maxlength="3000" rows="6" placeholder="Describe the problem, your idea, and how it would help…"></textarea></label><div class="form-foot"><span class="muted">Keep it clear. Keep it constructive.</span><button class="button" type="submit">Submit idea ↗</button></div></form></section>';layout('Submit an idea',$content,$appName);exit;
}

if($path==='/roadmap'){
    $groups=['Planned'=>'On the horizon','In Progress'=>'Currently building','Completed'=>'Shipped'];
    $content='<section class="page-intro"><div class="eyebrow">THE PRODUCT JOURNEY</div><h1>From good ideas<br>to <span>great releases.</span></h1><p class="lead">A transparent look at what we are thinking about, building and shipping.</p><a class="button" href="/submit">Suggest what is next ↗</a></section>';
    foreach($groups as $status=>$subtitle){$s=$pdo->prepare('SELECT * FROM feedback WHERE status=? ORDER BY updated_at DESC');$s->execute([$status]);$rows=$s->fetchAll();$content.='<section class="roadmap-group"><div class="roadmap-title"><div><span class="status '.statusClass($status).'">'.e($status).'</span><h2>'.e($subtitle).'</h2></div><span class="count">'.count($rows).' items</span></div>';
        if(!$rows)$content.='<div class="empty compact"><p>Nothing here just yet.</p></div>';
        foreach($rows as $row)$content.='<article class="roadmap-card"><div><h3><a href="/feedback/'.(int)$row['id'].'">'.e($row['title']).'</a></h3><p>'.e($row['body']).'</p></div><span class="vote-count">↑ '.(int)$row['votes'].'</span></article>';$content.='</section>';
    }
    layout('Roadmap',$content,$appName);exit;
}

$q=trim((string)($_GET['q']??''));$filter=(string)($_GET['status']??'All');
$sql="SELECT f.*,COUNT(c.id) comment_count FROM feedback f LEFT JOIN comments c ON c.feedback_id=f.id WHERE 1=1";$params=[];
if($q!==''){$sql.=' AND (f.title LIKE ? OR f.body LIKE ?)';$params[]='%'.$q.'%';$params[]='%'.$q.'%';}
if(in_array($filter,['Under Review','Planned','In Progress','Completed','Declined'],true)){$sql.=' AND f.status=?';$params[]=$filter;}
$sql.=' GROUP BY f.id ORDER BY f.votes DESC,f.created_at DESC';$s=$pdo->prepare($sql);$s->execute($params);$items=$s->fetchAll();
$open=(int)$pdo->query("SELECT COUNT(*) FROM feedback WHERE status IN ('Under Review','Planned','In Progress')")->fetchColumn();
$content='<section class="hero"><div class="hero-copy"><div class="eyebrow"><span class="live-dot"></span> BUILT WITH THE COMMUNITY</div><h1>Good ideas.<br><span>Better products.</span></h1><p class="lead">This is where the next great improvement starts. Share what matters, vote for what you love, and follow what we build.</p><div class="hero-actions"><a class="button" href="/submit">Share an idea <span>↗</span></a><a class="quiet-link" href="/roadmap">Explore the roadmap →</a></div></div><div class="hero-card"><div class="hero-card-top"><span>COMMUNITY PULSE</span><span class="pulse-icon">✳</span></div><strong>'.$open.'</strong><p>ideas in the pipeline</p><div class="pulse-line"><span></span></div><small>Every great feature starts with a conversation.</small></div></section>';
$content.='<section class="board"><div class="section-heading"><div><span class="eyebrow">THE IDEA EXCHANGE</span><h2>Community feedback</h2><p class="muted">Vote on ideas you want to see come to life.</p></div><span class="count">'.count($items).' results</span></div><form class="filters" method="get"><input type="search" name="q" value="'.e($q).'" placeholder="Search ideas…"><select name="status"><option>All</option>';
foreach(['Under Review','Planned','In Progress','Completed','Declined'] as $st)$content.='<option '.($filter===$st?'selected':'').'>'.e($st).'</option>';
$content.='</select><button class="button button-dark" type="submit">Filter</button></form>';
if($items){foreach($items as $it)$content.=feedbackCard($it);}else $content.='<div class="empty"><div class="empty-mark">✳</div><h3>No ideas found.</h3><p>Try a different search, or be the first to suggest something.</p><a class="text-link" href="/submit">Share an idea →</a></div>';
$content.='</section>';layout('Community feedback',$content,$appName);
