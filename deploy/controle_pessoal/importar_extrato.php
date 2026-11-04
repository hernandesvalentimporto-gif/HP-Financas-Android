<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require_login();

$pdo = db();
$uid = (int)($_SESSION['user_id'] ?? 0);
$error = '';
$notice = '';
$report = null;
$preview = null;
$accounts = [];

function imp_escape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function imp_norm(string $value): string {
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = strtr($value, [
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ï'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ü'=>'u','ç'=>'c'
    ]);
    return preg_replace('/\s+/u', ' ', $value) ?? $value;
}
function imp_date($raw): ?string {
    $raw = trim((string)$raw);
    $formats = ['!d/m/Y','!d/m/y','!Y-m-d','!d-m-Y','!Y/m/d','!Ymd'];
    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $raw);
        $errors = DateTimeImmutable::getLastErrors();
        $valid = $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
        if ($valid) return $date->format('Y-m-d');
    }
    return null;
}
function imp_money($raw): ?float {
    $value = trim(str_replace(["\xc2\xa0", 'R$', ' '], '', (string)$raw));
    if ($value === '' || $value === '-') return null;
    $negative = false;
    if (preg_match('/^\((.*)\)$/', $value, $match)) { $negative = true; $value = $match[1]; }
    if (substr($value, 0, 1) === '-') { $negative = true; $value = substr($value, 1); }
    if (strpos($value, ',') !== false) { $value = str_replace('.', '', $value); $value = str_replace(',', '.', $value); }
    if (!is_numeric($value)) return null;
    $number = (float)$value;
    return $negative ? -abs($number) : abs($number);
}
function imp_delimiter(string $line): string {
    $candidates = [';', ',', "\t"];
    $best = ','; $bestCount = -1;
    foreach ($candidates as $candidate) { $count = substr_count($line, $candidate); if ($count > $bestCount) { $best = $candidate; $bestCount = $count; } }
    return $best;
}
function imp_csv_rows(string $path): array {
    $handle = fopen($path, 'rb');
    if (!$handle) throw new RuntimeException('Não foi possível abrir o CSV.');
    $first = fgets($handle);
    if ($first === false) { fclose($handle); throw new RuntimeException('O arquivo está vazio.'); }
    $delimiter = imp_delimiter($first);
    rewind($handle);
    $rows = [];
    while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
        if (isset($row[0])) $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$row[0]);
        $rows[] = $row;
    }
    fclose($handle);
    return $rows;
}
function imp_find(array $values, array $options): ?int {
    foreach ($values as $index => $value) if (in_array(imp_norm((string)$value), $options, true)) return (int)$index;
    return null;
}
function imp_parse_csv(array $rows, string $bank): array {
    $headerIndex = -1; $map = [];
    foreach (array_slice($rows, 0, 50, true) as $index => $row) {
        $date = imp_find($row, ['data','data lancamento','data lançamento','data da transacao','data da transação','date']);
        $value = imp_find($row, ['valor','valor r$','valor (r$)','valor da transacao','valor da transação','amount']);
        $description = imp_find($row, ['descricao','descrição','detalhe','detalhes','description','memo']);
        $history = imp_find($row, ['historico','histórico','tipo de transacao','tipo de transação','historico da transacao']);
        if ($date !== null && $value !== null && ($description !== null || $history !== null)) {
            $headerIndex = (int)$index;
            $map = ['date'=>$date,'value'=>$value,'description'=>$description,'history'=>$history];
            break;
        }
    }
    if ($headerIndex < 0) throw new RuntimeException('Não reconheci o CSV. Ele precisa conter Data, Valor e Descrição ou Histórico.');
    $parsed = [];
    for ($i = $headerIndex + 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        $date = imp_date($row[$map['date']] ?? '');
        $amount = imp_money($row[$map['value']] ?? '');
        $description = trim((string)($map['description'] !== null ? ($row[$map['description']] ?? '') : ''));
        $history = trim((string)($map['history'] !== null ? ($row[$map['history']] ?? '') : ''));
        if ($description === '') $description = $history;
        if (!$date || $amount === null || abs($amount) < 0.00001 || $description === '') continue;
        $description = mb_substr($description, 0, 180, 'UTF-8');
        $combined = imp_norm($description . ' ' . $history);
        $transfer = (bool)preg_match('/\b(transferencia|transferência|ted recebida|ted enviada|entre contas|transferencia recebida|transferencia enviada|transferência recebida|transferência enviada)\b/u', $combined);
        $parsed[] = ['date'=>$date,'description'=>$description,'history'=>$history,'amount'=>round(abs($amount), 2),'kind'=>$amount > 0 ? 'income' : 'expense','transfer'=>$transfer,'bank'=>$bank];
    }
    return $parsed;
}
function imp_tag_value(string $text, string $tag): string {
    if (preg_match('/<' . preg_quote($tag, '/') . '(?:>[^<]*<\/[^>]+>|[^>]*>)([^<\r\n]*)/i', $text, $match)) return trim(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'));
    if (preg_match('/<' . preg_quote($tag, '/') . '[^>]*>([^<]*)/i', $text, $match)) return trim($match[1]);
    return '';
}
function imp_parse_ofx(string $path, string $bank): array {
    $text = file_get_contents($path);
    if ($text === false || trim($text) === '') throw new RuntimeException('O arquivo OFX está vazio.');
    preg_match_all('/<STMTTRN>(.*?)(?:<\/STMTTRN>|(?=<STMTTRN>))/is', $text, $matches);
    $parsed = [];
    foreach ($matches[1] as $transaction) {
        $dateRaw = imp_tag_value($transaction, 'DTPOSTED');
        $date = imp_date(substr($dateRaw, 0, 8));
        $amount = imp_money(imp_tag_value($transaction, 'TRNAMT'));
        $description = imp_tag_value($transaction, 'NAME');
        if ($description === '') $description = imp_tag_value($transaction, 'MEMO');
        $history = imp_tag_value($transaction, 'MEMO');
        if (!$date || $amount === null || abs($amount) < 0.00001 || $description === '') continue;
        $parsed[] = ['date'=>$date,'description'=>mb_substr($description,0,180,'UTF-8'),'history'=>$history,'amount'=>round(abs($amount),2),'kind'=>$amount > 0 ? 'income' : 'expense','transfer'=>preg_match('/transfer|ted|entre contas/i', imp_norm($description.' '.$history)) === 1,'bank'=>$bank];
    }
    if (!$parsed) throw new RuntimeException('Não encontrei movimentações válidas no OFX.');
    return $parsed;
}
function imp_similarity(string $a, string $b): float {
    $a = imp_norm($a); $b = imp_norm($b);
    if ($a === $b) return 1.0;
    if ($a === '' || $b === '') return 0.0;
    similar_text($a, $b, $percent);
    return $percent / 100;
}

try {
    $query = $pdo->prepare('SELECT id,name,bank_name FROM fin_accounts WHERE user_id=? ORDER BY name');
    $query->execute([$uid]);
    $accounts = $query->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $exception) { $error = 'Não consegui carregar suas contas.'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    try {
        verify_csrf();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'preview') {
            $accountId = (int)($_POST['account_id'] ?? 0);
            $bank = ($_POST['bank'] ?? '') === 'C6' ? 'C6' : 'Inter';
            $validAccount = false;
            foreach ($accounts as $account) if ((int)$account['id'] === $accountId) $validAccount = true;
            if (!$validAccount) throw new RuntimeException('Selecione a conta correspondente ao extrato.');
            if (!isset($_FILES['statement']) || $_FILES['statement']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Selecione um arquivo CSV ou OFX.');
            if ((int)$_FILES['statement']['size'] > 8 * 1024 * 1024) throw new RuntimeException('O arquivo excede 8 MB.');
            $extension = strtolower(pathinfo((string)$_FILES['statement']['name'], PATHINFO_EXTENSION));
            $rows = $extension === 'ofx' ? imp_parse_ofx($_FILES['statement']['tmp_name'], $bank) : imp_parse_csv(imp_csv_rows($_FILES['statement']['tmp_name']), $bank);
            if (!$rows) throw new RuntimeException('Não encontrei movimentações válidas no arquivo.');
            $existingQuery = $pdo->prepare('SELECT id,kind,description,amount,transaction_date FROM fin_transactions WHERE user_id=? AND account_id=? AND transaction_date=? AND amount=? ORDER BY id ASC');
            $seen = [];
            foreach ($rows as &$row) {
                $key = $row['date'].'|'.$row['kind'].'|'.number_format((float)$row['amount'],2,'.','').'|'.imp_norm($row['description']);
                $row['file_duplicate'] = isset($seen[$key]);
                $seen[$key] = true;
                $row['match_id'] = null; $row['match_description'] = ''; $row['match_type'] = '';
                $existingQuery->execute([$uid,$accountId,$row['date'],number_format((float)$row['amount'],2,'.','')]);
                $candidates = $existingQuery->fetchAll(PDO::FETCH_ASSOC);
                foreach ($candidates as $candidate) {
                    if ($candidate['kind'] !== $row['kind']) continue;
                    $similarity = imp_similarity($row['description'], (string)$candidate['description']);
                    if ($similarity >= 0.68) {
                        $row['match_id'] = (int)$candidate['id'];
                        $row['match_description'] = (string)$candidate['description'];
                        $row['match_type'] = $similarity >= 0.99 ? 'exact' : 'similar';
                        break;
                    }
                }
            }
            unset($row);
            $_SESSION['statement_import_preview'] = ['account_id'=>$accountId,'bank'=>$bank,'rows'=>$rows,'created'=>time()];
        } elseif ($action === 'delete_duplicates') {
            $saved = $_SESSION['statement_import_preview'] ?? null;
            $ids = array_values(array_unique(array_map('intval', (array)($_POST['duplicate_ids'] ?? []))));
            if (!$saved || time() - (int)$saved['created'] > 1800) throw new RuntimeException('A prévia expirou. Selecione o arquivo novamente.');
            if (!$ids) throw new RuntimeException('Selecione pelo menos uma duplicidade para excluir.');
            $pdo->beginTransaction();
            $deleted = 0;
            $delete = $pdo->prepare("DELETE FROM fin_transactions WHERE id=? AND user_id=? AND COALESCE(notes,'') NOT LIKE 'HP_TRANSFER:%'");
            foreach ($ids as $id) { $delete->execute([$id,$uid]); $deleted += $delete->rowCount(); }
            $pdo->commit();
            unset($_SESSION['statement_import_preview']);
            $notice = $deleted . ' lançamento(s) duplicado(s) excluído(s). Se quiser, faça a prévia do arquivo novamente.';
        } elseif ($action === 'import') {
            $saved = $_SESSION['statement_import_preview'] ?? null;
            if (!$saved || time() - (int)$saved['created'] > 1800) throw new RuntimeException('A prévia expirou. Selecione o arquivo novamente.');
            $rows = $saved['rows']; $accountId = (int)$saved['account_id']; $bank = (string)$saved['bank'];
            $categoryIds = [];
            foreach (['income','expense'] as $kind) {
                $cat = $pdo->prepare("SELECT id FROM fin_categories WHERE (user_id IS NULL OR user_id=?) AND (kind=? OR kind='both') ORDER BY CASE WHEN name='Outros' THEN 1 ELSE 0 END,name LIMIT 1");
                $cat->execute([$uid,$kind]); $categoryIds[$kind] = $cat->fetchColumn() ?: null;
            }
            $exists = $pdo->prepare('SELECT id,description FROM fin_transactions WHERE user_id=? AND account_id=? AND transaction_date=? AND amount=? AND kind=?');
            $insert = $pdo->prepare("INSERT INTO fin_transactions(user_id,account_id,category_id,kind,description,amount,transaction_date,due_date,status,payment_method,notes) VALUES(?,?,?,?,?,?,?,NULL,'paid',?,?)");
            $added = 0; $blocked = 0; $transfers = 0; $income = 0.0; $expense = 0.0; $names = [];
            $pdo->beginTransaction();
            $runKeys = [];
            foreach ($rows as $row) {
                if (!empty($row['transfer'])) { $transfers++; continue; }
                $key = $row['date'].'|'.$row['kind'].'|'.number_format((float)$row['amount'],2,'.','').'|'.imp_norm($row['description']);
                if (isset($runKeys[$key])) { $blocked++; continue; }
                $runKeys[$key] = true;
                $amount = number_format((float)$row['amount'], 2, '.', '');
                $exists->execute([$uid,$accountId,$row['date'],$amount,$row['kind']]);
                $found = false;
                foreach ($exists->fetchAll(PDO::FETCH_ASSOC) as $candidate) if (imp_similarity($row['description'], (string)$candidate['description']) >= 0.68) { $found = true; break; }
                if ($found) { $blocked++; continue; }
                $payment = stripos($row['history'], 'pix') !== false ? 'Pix' : (stripos($row['history'], 'ted') !== false ? 'TED' : 'Débito');
                $notes = 'Importado do extrato '.$bank.' (CSV/OFX). Histórico: '.trim($row['history']);
                $insert->execute([$uid,$accountId,$categoryIds[$row['kind']],$row['kind'],$row['description'],$amount,$row['date'],$payment,$notes]);
                $added++; $names[] = $row; if ($row['kind'] === 'income') $income += (float)$row['amount']; else $expense += (float)$row['amount'];
            }
            $pdo->commit(); unset($_SESSION['statement_import_preview']);
            $report = ['bank'=>$bank,'added'=>$added,'blocked'=>$blocked,'transfers'=>$transfers,'income'=>$income,'expense'=>$expense,'names'=>$names];
        } elseif ($action === 'cancel') { unset($_SESSION['statement_import_preview']); $notice = 'Prévia cancelada.'; }
        else throw new RuntimeException('Ação inválida.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $exception instanceof PDOException ? 'O banco recusou a operação. Nenhuma alteração foi gravada.' : $exception->getMessage();
    }
}
if (!$error && isset($_SESSION['statement_import_preview'])) {
    $saved = $_SESSION['statement_import_preview'];
    if (time() - (int)$saved['created'] <= 1800) $preview = $saved; else unset($_SESSION['statement_import_preview']);
}
?>
<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Importar extrato · HP Finanças</title><link rel="stylesheet" href="assets/style.css?v=importacao20261104"><style>
.import-table{width:100%;border-collapse:collapse}.import-table th,.import-table td{text-align:left;padding:9px;border-bottom:1px solid #7775;font-size:14px}.table-wrap{overflow-x:auto}.income{color:#188754}.expense{color:#c43e46}.duplicate{color:#b26a00;background:#b26a0012}.import-form{display:grid;gap:12px}.import-form select,.import-form input,.import-form button{min-height:48px;box-sizing:border-box;width:100%}.duplicate-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:12px}.duplicate-actions button{width:auto}.help{color:#aeb0bf;font-size:.92rem}
</style></head><body class="app-bg"><main class="main" style="max-width:1100px;margin:0 auto;padding:20px"><header class="topbar"><div><p class="eyebrow">HP FINANÇAS / MOVIMENTAÇÕES</p><h1>Importar extrato<span class="dot">.</span></h1></div><a class="btn secondary" href="index.php">Voltar ao painel</a></header><p>Importe CSV ou OFX do <strong>Banco Inter</strong> ou <strong>C6 Bank</strong>. A importação nunca substitui lançamentos existentes e bloqueia registros iguais ou muito parecidos.</p><?php if($error):?><div class="alert"><?=imp_escape($error)?></div><?php endif;?><?php if($notice):?><div class="success"><?=imp_escape($notice)?></div><?php endif;?>
<?php if($report):?><div class="success"><strong>Importação concluída — <?=imp_escape($report['bank'])?>.</strong><br>Novos lançamentos: <?= (int)$report['added'] ?><br>Receitas: R$ <?=number_format($report['income'],2,',','.')?><br>Despesas: R$ <?=number_format($report['expense'],2,',','.')?><br>Bloqueados por duplicidade: <?= (int)$report['blocked'] ?><br>Transferências deixadas fora para conferência: <?= (int)$report['transfers'] ?></div><?php if($report['names']):?><section class="panel"><h3>Novos lançamentos</h3><div class="table-wrap"><table class="import-table"><tr><th>Data</th><th>Tipo</th><th>Descrição</th><th>Valor</th></tr><?php foreach($report['names'] as $row):?><tr><td><?=imp_escape(date('d/m/Y',strtotime($row['date'])))?></td><td class="<?=imp_escape($row['kind'])?>"><?=$row['kind']==='income'?'Receita':'Despesa'?></td><td><?=imp_escape($row['description'])?></td><td>R$ <?=number_format((float)$row['amount'],2,',','.')?></td></tr><?php endforeach;?></table></div></section><?php endif;?><?php endif;?>
<?php if($preview): $rows=$preview['rows']; $new=0;$duplicates=[];$fileDuplicates=0;$transfers=0;foreach($rows as $row){if(!empty($row['transfer']))$transfers++;elseif(!empty($row['match_id']))$duplicates[]=$row;elseif(!empty($row['file_duplicate']))$fileDuplicates++;else$new++;} $accountName='Conta selecionada';foreach($accounts as $account)if((int)$account['id']===(int)$preview['account_id'])$accountName=$account['name'];?><section class="panel" style="margin-top:18px"><h2>Confira antes de importar</h2><p>Banco: <strong><?=imp_escape($preview['bank'])?></strong> · Conta: <strong><?=imp_escape($accountName)?></strong></p><p>Novos: <strong><?=$new?></strong> · Bloqueados por duplicidade: <strong><?=count($duplicates)+$fileDuplicates?></strong> · Transferências: <strong><?=$transfers?></strong></p><p class="help">Registros bloqueados não serão gravados. Se um lançamento antigo estiver errado, selecione-o abaixo para excluí-lo e faça a prévia novamente.</p><?php if($duplicates):?><form method="post"><input type="hidden" name="csrf" value="<?=imp_escape(csrf())?>"><input type="hidden" name="action" value="delete_duplicates"><div class="table-wrap"><table class="import-table"><tr><th>Excluir?</th><th>Data</th><th>Descrição do arquivo</th><th>Registro já existente</th><th>Valor</th></tr><?php foreach($duplicates as $row):?><tr class="duplicate"><td><input type="checkbox" name="duplicate_ids[]" value="<?=(int)$row['match_id']?>"></td><td><?=imp_escape(date('d/m/Y',strtotime($row['date'])))?></td><td><?=imp_escape($row['description'])?></td><td><?=imp_escape($row['match_description'])?> <small>(<?=$row['match_type']==='exact'?'igual':'parecido'?>)</small></td><td>R$ <?=number_format((float)$row['amount'],2,',','.')?></td></tr><?php endforeach;?></table></div><div class="duplicate-actions"><button class="btn secondary" type="submit" onclick="return confirm('Excluir somente os lançamentos selecionados? Esta ação não pode ser desfeita.')">Excluir selecionados</button><span class="help">Depois faça a prévia do arquivo novamente para liberar esses lançamentos.</span></div></form><?php endif;?><div class="table-wrap" style="margin-top:16px"><table class="import-table"><tr><th>Data</th><th>Tipo</th><th>Descrição</th><th>Valor</th><th>Situação</th></tr><?php foreach(array_slice($rows,0,150) as $row):?><tr><td><?=imp_escape(date('d/m/Y',strtotime($row['date'])))?></td><td class="<?=$row['transfer']?'duplicate':imp_escape($row['kind'])?>"><?=$row['transfer']?'Transferência':($row['kind']==='income'?'Receita':'Despesa')?></td><td><?=imp_escape($row['description'])?></td><td>R$ <?=number_format((float)$row['amount'],2,',','.')?></td><td><?=!empty($row['match_id'])?'Duplicidade bloqueada':(!empty($row['file_duplicate'])?'Repetida no arquivo':($row['transfer']?'Fora da importação':'Nova'))?></td></tr><?php endforeach;?></table></div><form method="post" style="margin-top:16px"><input type="hidden" name="csrf" value="<?=imp_escape(csrf())?>"><input type="hidden" name="action" value="import"><button class="btn primary" type="submit" onclick="return confirm('Importar somente os lançamentos novos? Os duplicados e transferências serão bloqueados.')">Importar lançamentos novos</button></form><form method="post" style="margin-top:8px"><input type="hidden" name="csrf" value="<?=imp_escape(csrf())?>"><input type="hidden" name="action" value="cancel"><button class="btn secondary" type="submit">Cancelar prévia</button></form></section><?php else:?><section class="panel" style="margin-top:18px"><form class="import-form" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=imp_escape(csrf())?>"><input type="hidden" name="action" value="preview"><label for="bank">Banco do extrato</label><select id="bank" name="bank" required><option value="Inter">Banco Inter</option><option value="C6">C6 Bank</option></select><label for="account_id">Conta de destino</label><select id="account_id" name="account_id" required><option value="">Selecione a conta</option><?php foreach($accounts as $account):?><option value="<?=(int)$account['id']?>"><?=imp_escape($account['name'])?><?=!empty($account['bank_name'])?' — '.imp_escape($account['bank_name']):''?></option><?php endforeach;?></select><label for="statement">Arquivo CSV ou OFX</label><input id="statement" type="file" name="statement" accept=".csv,.ofx,text/csv,application/x-ofx" required><button class="btn primary" type="submit">Analisar arquivo e mostrar prévia</button></form><p class="help" style="margin-top:14px">O valor positivo vira receita e o negativo vira despesa. Transferências são deixadas fora para não inflar seus totais.</p></section><?php endif;?><p style="margin-top:20px"><a href="index.php">HP Finanças</a></p></main></body></html>
