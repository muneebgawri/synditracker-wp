# Synditracker Agent: Deployment Guide
**Author**: Muneeb Gawri ([muneebgawri.com](https://muneebgawri.com))

The Synditracker Agent is a lightweight component that reports press release syndication back to the Synditracker Core Hub.

## Option 1: Full Plugin Installation (Recommended)
1. Upload the `synditracker-agent` folder to your WordPress `wp-content/plugins/` directory.
2. Activate the plugin in the WordPress Dashboard.
3. Go to **Settings > Synditracker Agent**.
4. Enter the **Hub URL** (e.g., `https://yoursite.com`) and your provided **Site Key**.
5. Save Changes. The agent will now automatically report every new press release import.

## Option 2: Standalone PHP Snippet
If you prefer not to install a plugin, you can add this snippet to your theme's `functions.php`:

```php
/**
 * Synditracker Reporting Snippet (by Muneeb Gawri)
 */
add_action('wp_insert_post', function($post_ID, $post, $update) {
    if ($update) return;

    $guid = get_the_guid($post_ID);
    if (!preg_match('/[?&]p=(\d+)/', $guid, $matches)) return;
    $original_post_id = (int) $matches[1];

    $hub_url = 'YOUR_HUB_URL'; // e.g. https://yoursite.com
    $site_key = 'YOUR_SITE_KEY';

    wp_remote_post(trailingslashit($hub_url) . 'wp-json/synditracker/v1/log', [
        'method'      => 'POST',
        'blocking'    => false,
        'headers'     => ['X-Synditracker-Key' => $site_key, 'Content-Type' => 'application/json'],
        'body'        => json_encode([
            'post_id'    => $original_post_id,
            'site_url'   => home_url(),
            'site_name'  => get_bloginfo('name'),
            'aggregator' => (get_post_meta($post_ID, 'feedzy_item_url', true) ? 'Feedzy' : 'Simple Import')
        ])
    ]);
}, 10, 3);
```

## Troubleshooting
- **Logs**: Check `wp-content/synditracker-agent.log` if data is not appearing in the Hub.
- **Firewall**: Ensure your server can make outbound POST requests to the Hub URL.
