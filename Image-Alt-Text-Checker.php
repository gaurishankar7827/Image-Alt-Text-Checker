<?php

add_action('admin_menu', function () {
    add_menu_page('Image Alt Checker', 'Image Alt Checker', 'manage_options', 'image-alt-checker', 'image_alt_checker_render_page');
});

function image_alt_checker_render_page() {
    echo '<div class="wrap"><h1>Images Missing All Alt Texts (Media + Frontend)</h1>';

    echo '<style>
        .image-alt-pagination { margin-top: 20px; }
        .image-alt-pagination a {
            display: inline-block;
            padding: 6px 12px;
            margin-right: 5px;
            border: 1px solid #ccc;
            background: #f9f9f9;
            text-decoration: none;
            border-radius: 3px;
        }
        .image-alt-pagination a.current-page {
            background: #007cba;
            color: white;
            font-weight: bold;
            border-color: #007cba;
        }
    </style>';

    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $per_page = 20;
    $offset = ($paged - 1) * $per_page;

    $all_query = new WP_Query([
        'post_type'      => 'attachment',
        'post_mime_type' => 'image',
        'post_status'    => 'inherit',
        'posts_per_page' => -1,
        'meta_query'     => [
            [
                'key'     => '_wp_attachment_image_alt',
                'compare' => 'NOT EXISTS',
            ],
        ],
    ]);

    $filtered_images = [];
    foreach ($all_query->posts as $attachment) {
        $id = $attachment->ID;
        $url = wp_get_attachment_url($id);
        $usages = image_alt_checker_find_usages($id);

        if (!empty($usages)) {
            $has_alt_anywhere = false;

            foreach ($usages as $post) {
                if (!image_alt_checker_is_alt_missing_frontend($post->ID, $url)) {
                    $has_alt_anywhere = true;
                    break;
                }
            }

            if (!$has_alt_anywhere) {
                $filtered_images[] = [
                    'id' => $id,
                    'url' => $url,
                    'date' => get_the_date('', $id),
                    'usages' => $usages,
                ];
            }
        }
    }

    $total_items = count($filtered_images);
    $total_pages = ceil($total_items / $per_page);

    if ($total_items === 0) {
        echo '<p>✅ No images are missing alt text both in Media Library and all frontend uses.</p></div>';
        return;
    }

    echo '<table class="widefat fixed striped">';
    echo '<thead><tr><th>Image ID</th><th>Image URL</th><th>Date Uploaded</th><th>Used On (Frontend)</th></tr></thead><tbody>';

    $displayed = array_slice($filtered_images, $offset, $per_page);
    foreach ($displayed as $image) {
        echo '<tr>';
        echo '<td>' . esc_html($image['id']) . '</td>';
        echo '<td><a href="' . esc_url($image['url']) . '" target="_blank">' . esc_html($image['url']) . '</a></td>';
        echo '<td>' . esc_html($image['date']) . '</td>';
        echo '<td>';
        foreach ($image['usages'] as $post) {
            echo '<strong style="color:red;">⚠️ Missing Alt:</strong> ';
            echo '<a href="' . esc_url(get_permalink($post->ID)) . '" target="_blank">';
            echo esc_html(get_the_title($post)) . ' (' . esc_html($post->post_type) . ')';
            echo '</a><br>';
        }
        echo '</td></tr>';
    }

    echo '</tbody></table>';

    if ($total_pages > 1) {
        echo '<div class="image-alt-pagination">';
        for ($i = 1; $i <= $total_pages; $i++) {
            $class = ($i === $paged) ? ' class="current-page"' : '';
            $link = admin_url('admin.php?page=image-alt-checker&paged=' . $i);
            echo '<a' . $class . ' href="' . esc_url($link) . '">' . $i . '</a>';
        }
        echo '</div>';
    }

    echo '</div>';
}

function image_alt_checker_find_usages($attachment_id) {
    global $wpdb;
    $url = wp_get_attachment_url($attachment_id);
    $like = '%' . $wpdb->esc_like($url) . '%';

    $results = $wpdb->get_results("
        SELECT ID, post_title, post_type
        FROM {$wpdb->posts}
        WHERE post_status = 'publish'
        AND post_type NOT IN ('attachment', 'revision', 'nav_menu_item')
        AND post_content LIKE '{$like}'
    ");

    return $results;
}

function image_alt_checker_is_alt_missing_frontend($post_id, $image_url) {
    $html = apply_filters('the_content', get_post_field('post_content', $post_id));

    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);

    $tags = $doc->getElementsByTagName('img');
    foreach ($tags as $tag) {
        $src = $tag->getAttribute('src');
        if (strpos($src, $image_url) !== false) {
            $alt = $tag->getAttribute('alt');
            if (trim($alt) !== '') {
                return false; // alt exists
            }
        }
    }

    return true; // all occurrences are missing alt
}

?>