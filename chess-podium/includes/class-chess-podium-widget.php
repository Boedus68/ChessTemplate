<?php
/**
 * Chess Podium - Published Tournaments Widget
 *
 * @package Chess_Podium
 */

if (!defined('ABSPATH')) {
    exit;
}

final class ChessPodium_Widget extends WP_Widget
{
    public function __construct()
    {
        parent::__construct(
            'chess_podium_tornei',
            __('Chess Podium - Tournaments', 'chess-podium'),
            [
                'description' => __('Display published chess tournaments in sidebar or homepage.', 'chess-podium'),
                'classname'   => 'widget_chess_podium_tornei',
            ]
        );
    }

    public function widget($args, $instance): void
    {
        $title = !empty($instance['title']) ? $instance['title'] : __('Published tournaments', 'chess-podium');
        $title = apply_filters('widget_title', $title, $instance, $this->id_base);
        $limit = isset($instance['limit']) ? max(1, (int) $instance['limit']) : 5;
        $show_preview = !empty($instance['show_preview']);

        $published = ChessPodiumPlugin::get_published_tournaments();
        if (empty($published)) {
            return;
        }

        $published = array_slice($published, 0, $limit);

        echo $args['before_widget'];
        if ($title) {
            echo $args['before_title'] . esc_html($title) . $args['after_title'];
        }

        echo '<ul class="chess-podium-widget-list">';
        foreach ($published as $item) {
            $roundsText = sprintf(_n('%d round', '%d rounds', (int) $item['rounds'], 'chess-podium'), (int) $item['rounds']);
            echo '<li class="chess-podium-widget-item">';
            echo '<a href="' . esc_url($item['url']) . '" class="chess-podium-widget-link" target="_blank" rel="noopener">';
            if ($show_preview) {
                echo '<span class="chess-podium-widget-preview"><img src="' . esc_url($item['preview_url']) . '" alt="" loading="lazy"></span>';
            }
            echo '<span class="chess-podium-widget-info">';
            echo '<span class="chess-podium-widget-name">' . esc_html($item['name']) . '</span>';
            echo '<span class="chess-podium-widget-meta">' . esc_html($roundsText) . '</span>';
            echo '</span>';
            echo '</a>';
            echo '</li>';
        }
        echo '</ul>';

        $torneiPageId = (int) get_option('chess_podium_tornei_page_id', 0);
        if ($torneiPageId > 0) {
            $link = get_permalink($torneiPageId);
            if ($link) {
                echo '<p class="chess-podium-widget-more"><a href="' . esc_url($link) . '">' . esc_html__('View all tournaments', 'chess-podium') . '</a></p>';
            }
        }

        echo $args['after_widget'];
    }

    public function form($instance): void
    {
        $title = !empty($instance['title']) ? $instance['title'] : '';
        $limit = isset($instance['limit']) ? (int) $instance['limit'] : 5;
        $show_preview = !empty($instance['show_preview']);
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php esc_html_e('Title:', 'chess-podium'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($title); ?>" placeholder="<?php echo esc_attr__('Published tournaments', 'chess-podium'); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('limit')); ?>"><?php esc_html_e('Number of tournaments:', 'chess-podium'); ?></label>
            <input class="tiny-text" id="<?php echo esc_attr($this->get_field_id('limit')); ?>" name="<?php echo esc_attr($this->get_field_name('limit')); ?>" type="number" min="1" max="20" value="<?php echo esc_attr($limit); ?>">
        </p>
        <p>
            <input class="checkbox" id="<?php echo esc_attr($this->get_field_id('show_preview')); ?>" name="<?php echo esc_attr($this->get_field_name('show_preview')); ?>" type="checkbox" <?php checked($show_preview); ?>>
            <label for="<?php echo esc_attr($this->get_field_id('show_preview')); ?>"><?php esc_html_e('Show preview image', 'chess-podium'); ?></label>
        </p>
        <?php
    }

    public function update($new_instance, $old_instance): array
    {
        $instance = [];
        $instance['title'] = sanitize_text_field($new_instance['title'] ?? '');
        $instance['limit'] = isset($new_instance['limit']) ? max(1, min(20, (int) $new_instance['limit'])) : 5;
        $instance['show_preview'] = !empty($new_instance['show_preview']);
        return $instance;
    }
}
