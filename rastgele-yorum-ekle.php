<?php
/*
Plugin Name: meyiLbot AI Comments
Description: Blog yazılarınız için Gemini 2.5 yapay zekası kullanarak otomatik, zamanlanmış ve doğal yorumlar oluşturan akıllı bir yorum botudur.
Version: 3.3
Author: <a href="https://meyil.net" target="_blank">meyiL</a>
*/

// Doğrudan erişimi engelle
defined('ABSPATH') || die('Direct access not allowed');

// Eklenti aktivasyonunda varsayılan ayarlar ve dosya kontrolü
register_activation_hook(__FILE__, 'meyilbot_aktivasyon');
function meyilbot_aktivasyon() {
    $plugin_dir = plugin_dir_path(__FILE__);
    $files = ['ad.txt', 'soyad.txt', 'ip.txt', 'email.txt', 'site.txt'];
    foreach ($files as $file) {
        if (!file_exists($plugin_dir . $file)) {
            file_put_contents($plugin_dir . $file, '');
        }
    }
    
    // Varsayılan seçenekler
    add_option('gemini_api_key', '');
    add_option('meyil_oto_min', '15');
    add_option('meyil_oto_max', '28');
    add_option('meyil_oto_prompt', 'Bu yazıya kısa ve doğal bir yorum yap.');
    add_option('meyil_oto_durum', 'pasif');

    // .htaccess dosyası oluştur
    $htaccess = $plugin_dir . '.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Deny from all\n");
    }
}

// Eklentiler sayfasında "Ayarlar" linki ekleme
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'meyilbot_ayarlar_link');
function meyilbot_ayarlar_link($links) {
    $ayarlar_link = '<a href="' . admin_url('admin.php?page=meyilbot-ai') . '">Ayarlar</a>';
    $web_sitesi_link = '<a href="https://meyil.net" target="_blank">Web Sitesi</a>';
    array_unshift($links, $web_sitesi_link);
    array_unshift($links, $ayarlar_link);
    return $links;
}

// Eklenti pasif olursa cron'u temizle
register_deactivation_hook(__FILE__, 'meyilbot_deaktivasyon');
function meyilbot_deaktivasyon() {
    wp_clear_scheduled_hook('meyilbot_cron_event');
}

// Cron (Zamanlanmış Görev) Kancası
add_action('meyilbot_cron_event', 'meyilbot_otomatik_islem');

// Admin menü
add_action('admin_menu', 'meyilbot_menu');
function meyilbot_menu() {
    $icon_url = plugin_dir_url(__FILE__) . 'meyil-ai.svg';
    add_menu_page('meyiLbot AI', 'meyiLbot AI', 'manage_options', 'meyilbot-ai', 'meyilbot_ayarlar_sayfasi', $icon_url);
}

// Scripts ve Stil
add_action('admin_enqueue_scripts', 'meyilbot_scripts');
function meyilbot_scripts($hook) {
    if ($hook !== 'toplevel_page_meyilbot-ai') return;
    wp_enqueue_style('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css');
    wp_enqueue_script('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js', array('jquery'), null, true);
    wp_add_inline_script('select2', 'jQuery(document).ready(function($) { $("#yazi_secim_gemini").select2({ placeholder: "Yazı seçin veya arayın", allowClear: true }); });');
    
    // Menü ikonu CSS düzeltmesi
    wp_add_inline_style('admin-menu', '
        .toplevel_page_meyilbot-ai .wp-menu-image img { width: 20px; height: 20px; padding-top: 7px; opacity: 0.8; }
        .toplevel_page_meyilbot-ai:hover .wp-menu-image img, .toplevel_page_meyilbot-ai.current .wp-menu-image img { opacity: 1; }
    ');
}

// YARDIMCI: Rastgele Kullanıcı
function meyilbot_kullanici_getir() {
    $plugin_dir = plugin_dir_path(__FILE__);
    $adlar = file_exists($plugin_dir . 'ad.txt') ? file($plugin_dir . 'ad.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    $soyadlar = file_exists($plugin_dir . 'soyad.txt') ? file($plugin_dir . 'soyad.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    $ipler = file_exists($plugin_dir . 'ip.txt') ? file($plugin_dir . 'ip.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    $emailler = file_exists($plugin_dir . 'email.txt') ? file($plugin_dir . 'email.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    $siteler = file_exists($plugin_dir . 'site.txt') ? file($plugin_dir . 'site.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

    return array(
        'ad' => !empty($adlar) ? trim($adlar[array_rand($adlar)]) : 'Misafir',
        'soyad' => !empty($soyadlar) ? trim($soyadlar[array_rand($soyadlar)]) : '',
        'ip' => !empty($ipler) ? trim($ipler[array_rand($ipler)]) : '127.0.0.1',
        'email' => !empty($emailler) ? trim($emailler[array_rand($emailler)]) : 'info@example.com',
        'site' => !empty($siteler) ? trim($siteler[array_rand($siteler)]) : ''
    );
}

// YARDIMCI: Yorum Kaydet
function meyilbot_yorumu_kaydet($post_id, $icerik, $direkt_yayinla = 1) {
    $kullanici = meyilbot_kullanici_getir();
    $yorum_data = array(
        'comment_post_ID' => $post_id,
        'comment_author' => trim($kullanici['ad'] . ' ' . $kullanici['soyad']),
        'comment_content' => $icerik,
        'comment_author_IP' => $kullanici['ip'],
        'comment_author_email' => $kullanici['email'],
        'comment_author_url' => $kullanici['site'],
        'comment_approved' => $direkt_yayinla,
        'comment_date' => current_time('mysql')
    );
    return wp_insert_comment($yorum_data);
}

// API FONKSİYONU
function meyilbot_api_cagir($post_id, $prompt, $api_key, $yorum_index = 0) {
    $post = get_post($post_id);
    if (!$post) return "Hata: Yazı bulunamadı.";
    $model = 'gemini-2.5-flash';
    $post_content = strip_tags($post->post_content);
    if (strlen($post_content) > 12000) $post_content = substr($post_content, 0, 12000) . "...";
    $variety_prompt = $yorum_index > 0 ? " Lütfen önceki yorumlardan farklı bir üslup kullan. " : "";
    
    $request_json = array(
        "contents" => array(array("parts" => array(array("text" => "Makale İçeriği: \n" . $post_content . "\n\nİstek (Prompt): " . $variety_prompt . $prompt . "\n\nLütfen sadece yorum metnini döndür, başka açıklama yazma.")))),
        "safetySettings" => array(
            array("category" => "HARM_CATEGORY_HARASSMENT", "threshold" => "BLOCK_NONE"),
            array("category" => "HARM_CATEGORY_HATE_SPEECH", "threshold" => "BLOCK_NONE"),
            array("category" => "HARM_CATEGORY_SEXUALLY_EXPLICIT", "threshold" => "BLOCK_NONE"),
            array("category" => "HARM_CATEGORY_DANGEROUS_CONTENT", "threshold" => "BLOCK_NONE")
        )
    );

    $url = "https://generativelanguage.googleapis.com/v1beta/models/" . $model . ":generateContent?key=" . urlencode($api_key);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request_json));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) { $err = curl_error($ch); curl_close($ch); return "CURL Hatası: " . $err; }
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        $result = json_decode($response, true);
        return "API Hatası (Kod: $http_code): " . ($result['error']['message'] ?? 'Bilinmeyen Hata');
    }

    $result = json_decode($response, true);
    return $result['candidates'][0]['content']['parts'][0]['text'] ?? "Yorum oluşturulamadı.";
}

// OTO-PİLOT İŞLEMİ (LOG TEMİZLEME ÖZELLİĞİ EKLENDİ)
function meyilbot_otomatik_islem() {
    $durum = get_option('meyil_oto_durum');
    if ($durum !== 'aktif') return;

    $api_key = get_option('gemini_api_key');
    if (!$api_key) return;
    $prompt = get_option('meyil_oto_prompt');
    
    $args = array('numberposts' => 1, 'orderby' => 'comment_count', 'order' => 'ASC', 'post_status' => 'publish', 'post_type' => 'post');
    $yazilar = get_posts($args);

    if ($yazilar) {
        $yazi_id = $yazilar[0]->ID;
        $yorum_icerik = meyilbot_api_cagir($yazi_id, $prompt, $api_key);
        if ($yorum_icerik && strpos($yorum_icerik, 'Hata') === false && strpos($yorum_icerik, 'API Hatası') === false) {
            
            // 1. Yeni Yorumu Ekle
            $yorum_id = meyilbot_yorumu_kaydet($yazi_id, $yorum_icerik, 1);
            if ($yorum_id) {
                add_comment_meta($yorum_id, 'meyilbot_auto_log', current_time('mysql'), true);
            }

            // 2. Log Temizliği (Garbage Collection)
            // Sadece log meta etiketine sahip yorumları çek (Sınır yok, hepsini alıyoruz ki temizleyelim)
            $loglu_yorumlar = get_comments(array(
                'meta_key' => 'meyilbot_auto_log',
                'fields'   => 'ids',
                'orderby'  => 'comment_date',
                'order'    => 'DESC', // En yeniden eskiye
                'number'   => 50 // Performans için 50 çekelim, zaten 20'yi tutacağız
            ));

            // Eğer log sayısı 20'den fazlaysa, 20. sıradan sonrakilerin etiketini sil
            if (count($loglu_yorumlar) > 20) {
                $silinecekler = array_slice($loglu_yorumlar, 20);
                foreach ($silinecekler as $sil_id) {
                    delete_comment_meta($sil_id, 'meyilbot_auto_log');
                }
            }
        }
    }

    $min = intval(get_option('meyil_oto_min'));
    $max = intval(get_option('meyil_oto_max'));
    if ($min < 1) $min = 1;
    if ($max < $min) $max = $min;
    wp_schedule_single_event(time() + (rand($min, $max) * 60), 'meyilbot_cron_event');
}

// AYARLAR SAYFASI
function meyilbot_ayarlar_sayfasi() {
    if (!current_user_can('manage_options')) wp_die('Yetkiniz yok.');

    // KAYDETME İŞLEMLERİ
    if (isset($_POST['api_kaydet']) && check_admin_referer('meyilbot_nonce', 'meyilbot_nonce_field')) {
        update_option('gemini_api_key', sanitize_text_field($_POST['gemini_api_key']));
        echo '<div class="updated"><p>API Anahtarı kaydedildi.</p></div>';
    }

    if (isset($_POST['manuel_yorum']) && check_admin_referer('meyilbot_nonce', 'meyilbot_nonce_field')) {
        $yazi_id = sanitize_text_field($_POST['yazi_secim_gemini']);
        $prompt = sanitize_textarea_field($_POST['gemini_prompt']);
        $adet = intval($_POST['yorum_sayisi']);
        $api_key = get_option('gemini_api_key');

        if ($yazi_id && $api_key) {
            echo '<div class="updated">';
            for ($i = 0; $i < $adet; $i++) {
                $sonuc = meyilbot_api_cagir($yazi_id, $prompt, $api_key, $i);
                if (strpos($sonuc, 'Hata') !== false) {
                    echo "<p style='color:red;'>$sonuc</p>";
                } else {
                    meyilbot_yorumu_kaydet($yazi_id, $sonuc, 1);
                    echo "<p>✅ Yorum eklendi: " . wp_trim_words($sonuc, 10) . "</p>";
                }
            }
            echo '</div>';
        }
    }

    // OTO PİLOT
    if (isset($_POST['oto_pilot_kaydet']) && check_admin_referer('meyilbot_nonce', 'meyilbot_nonce_field')) {
        update_option('meyil_oto_min', intval($_POST['meyil_oto_min']));
        update_option('meyil_oto_max', intval($_POST['meyil_oto_max']));
        update_option('meyil_oto_prompt', sanitize_textarea_field($_POST['meyil_oto_prompt']));
        
        $yeni_durum = $_POST['meyil_oto_durum'];
        update_option('meyil_oto_durum', $yeni_durum);

        if ($yeni_durum === 'aktif') {
            if (!wp_next_scheduled('meyilbot_cron_event')) {
                wp_schedule_single_event(time() + 60, 'meyilbot_cron_event');
                echo '<div class="updated"><p>🚀 meyiLbot Oto-Pilot <strong>BAŞLATILDI!</strong> (1 dakika içinde ilk yorum gelecek)</p></div>';
            } else {
                echo '<div class="updated"><p>✅ Ayarlar güncellendi. Oto-Pilot zaten çalışıyor.</p></div>';
            }
        } elseif ($yeni_durum === 'pasif') {
            wp_clear_scheduled_hook('meyilbot_cron_event');
            echo '<div class="updated"><p>🛑 meyiLbot Oto-Pilot DURDURULDU.</p></div>';
        }
    }
    
    $api_key = get_option('gemini_api_key');
    $oto_min = get_option('meyil_oto_min');
    $oto_max = get_option('meyil_oto_max');
    $oto_prompt = get_option('meyil_oto_prompt');
    $oto_durum = get_option('meyil_oto_durum');
    $next_run = wp_next_scheduled('meyilbot_cron_event');
    ?>

    <div class="wrap">
        <h1>🤖 meyiLbot AI Comments v3.3</h1>
        
        <div style="background:#fff; padding:20px; border:1px solid #ccc; margin-bottom:20px;">
            <h3>🔑 API Ayarları</h3>
            <form method="post" action="">
                <?php wp_nonce_field('meyilbot_nonce', 'meyilbot_nonce_field'); ?>
                <p>
                    <label>Gemini API Anahtarı:</label>
                    <input type="password" name="gemini_api_key" value="<?php echo esc_attr($api_key); ?>" style="width:300px;">
                    <input type="submit" name="api_kaydet" class="button button-secondary" value="Kaydet">
                </p>
                <p class="description">Aktif Model: <strong>gemini-2.5-flash</strong> (En Hızlı ve Güncel)<br>
                <span style="color: #666; font-size: 12px;">API anahtarınızı almak için: <a href="https://aistudio.google.com/api-keys" target="_blank">🔗 https://aistudio.google.com/api-keys</a></span></p>
            </form>
        </div>

        <div style="display: flex; gap: 20px;">
            <div style="flex:1; background:#f0f9ff; padding:20px; border:1px solid #bae7ff;">
                <h2>✈️ Oto-Pilot</h2>
                <form method="post" action="">
                    <?php wp_nonce_field('meyilbot_nonce', 'meyilbot_nonce_field'); ?>
                    <table class="form-table">
                        <tr>
                            <th>Durum:</th>
                            <td>
                                <select name="meyil_oto_durum">
                                    <option value="pasif" <?php selected($oto_durum, 'pasif'); ?>>🛑 Pasif (Durdur)</option>
                                    <option value="aktif" <?php selected($oto_durum, 'aktif'); ?>>🚀 Aktif (Çalıştır)</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Zaman Aralığı (dk):</th>
                            <td>
                                <input type="number" name="meyil_oto_min" value="<?php echo esc_attr($oto_min); ?>" style="width:60px;"> - 
                                <input type="number" name="meyil_oto_max" value="<?php echo esc_attr($oto_max); ?>" style="width:60px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Yorum Talimatı (Prompt):</th>
                            <td><textarea name="meyil_oto_prompt" rows="3" style="width:100%;"><?php echo esc_textarea($oto_prompt); ?></textarea></td>
                        </tr>
                    </table>
                    <div style="margin-top:10px;"><input type="submit" name="oto_pilot_kaydet" class="button button-primary" value="Ayarları Uygula"></div>
                </form>
                <p><strong>Sonraki İşlem:</strong> <?php echo ($next_run) ? date("H:i:s", $next_run + (get_option('gmt_offset') * 3600)) : "<span style='color:red;'>Beklemede</span>"; ?></p>
            </div>

            <div style="flex:1; background:#fff; padding:20px; border:1px solid #ccc;">
                <h2>⚡ Hızlı Test</h2>
                <form method="post" action="">
                    <?php wp_nonce_field('meyilbot_nonce', 'meyilbot_nonce_field'); ?>
                    <p><select name="yazi_secim_gemini" id="yazi_secim_gemini" style="width: 100%;">
                        <?php
                        $yazilar = get_posts(array('numberposts' => 50, 'post_status' => 'publish'));
                        foreach ($yazilar as $yazi) { echo '<option value="' . $yazi->ID . '">' . esc_html($yazi->post_title) . '</option>'; }
                        ?>
                    </select></p>
                    <p><textarea name="gemini_prompt" rows="3" style="width:100%;" placeholder="Test Prompt..."></textarea></p>
                    <p>Adet: <select name="yorum_sayisi"><option value="1">1</option><option value="2">2</option></select></p>
                    <input type="submit" name="manuel_yorum" class="button button-secondary" value="Şimdi Yorum Yap">
                </form>
            </div>
        </div>

        <?php if ($oto_durum === 'aktif'): ?>
        <div style="margin-top:30px; background:#fff; padding:20px; border:1px solid #ccc;">
            <h2>📋 meyiLbot Oto-Log (Son 20 İşlem)</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th width="15%">Tarih</th><th width="20%">Yazı</th><th width="15%">Yazar</th><th>Yorum</th></tr></thead>
                <tbody>
                    <?php
                    $log_args = array('meta_key' => 'meyilbot_auto_log', 'number' => 20, 'orderby' => 'comment_date', 'order' => 'DESC', 'status' => 'all');
                    $bot_comments = get_comments($log_args);
                    if ($bot_comments) {
                        foreach ($bot_comments as $c) {
                            echo '<tr><td>' . $c->comment_date . '</td><td><a href="' . get_permalink($c->comment_post_ID) . '" target="_blank">' . esc_html(get_the_title($c->comment_post_ID)) . '</a></td><td>' . esc_html($c->comment_author) . '</td><td>' . esc_html(wp_trim_words($c->comment_content, 20)) . '</td></tr>';
                        }
                    } else { echo '<tr><td colspan="4">Henüz otomatik yorum yok.</td></tr>'; }
                    ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <div style="text-align: center; margin-top: 20px;">Geliştirici: <a href="https://meyil.net" target="_blank">meyiL</a></div>
    </div>
    <?php
}