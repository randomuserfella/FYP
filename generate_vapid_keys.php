<?php
/**
 * generate_vapid_keys.php
 * Generates a fresh VAPID key pair for Web Push. Open once in the browser,
 * copy the two values into config/secrets.php, then DELETE this file.
 *
 * If openssl cannot create EC keys on your machine (a known XAMPP-on-Windows
 * issue), generate them with Node.js instead:
 *
 *   node -e "const c=require('crypto');const k=c.generateKeyPairSync('ec',{namedCurve:'P-256'});
 *   const b=x=>Buffer.from(x).toString('base64url');
 *   console.log('PUBLIC:  '+b(k.publicKey.export({type:'spki',format:'der'}).slice(-65)));
 *   console.log('PRIVATE: '+b(k.privateKey.export({type:'pkcs8',format:'der'}).slice(36,68)));"
 */
function b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

$pub = $priv = null;
$res = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
if ($res) {
    $d = openssl_pkey_get_details($res);
    if (!empty($d['ec'])) {
        $pub  = b64url("\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT));
        $priv = b64url(str_pad($d['ec']['d'], 32, "\0", STR_PAD_LEFT));
    }
}
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>ProcraTrack VAPID Keys</title>
<style>
body{font-family:sans-serif;max-width:700px;margin:40px auto;padding:20px;background:#f5f5f5}
.card{background:#fff;border-radius:10px;padding:24px;margin-bottom:20px;border:1px solid #ddd}
code{display:block;background:#f0f0f0;padding:12px;border-radius:6px;word-break:break-all;font-size:13px;margin:8px 0}
.warn{background:#fff8e1;border:1px solid #f9a825;border-radius:8px;padding:14px}
</style></head><body>
<h2>ProcraTrack VAPID Keys</h2>
<?php if ($pub): ?>
<div class="card">
  <p><strong>VAPID_PUBLIC_KEY</strong></p><code><?= htmlspecialchars($pub) ?></code>
  <p><strong>VAPID_PRIVATE_KEY</strong></p><code><?= htmlspecialchars($priv) ?></code>
  <p>Paste both into <code style="display:inline;padding:2px 6px">config/secrets.php</code>. Reload this page to get a different pair.</p>
</div>
<?php else: ?>
<div class="card"><p>openssl could not create an EC key here. Use the Node.js command in the comment at the top of this file.</p></div>
<?php endif; ?>
<div class="warn"><strong>Delete this file</strong> after copying the keys. Never commit private keys.</div>
</body></html>
