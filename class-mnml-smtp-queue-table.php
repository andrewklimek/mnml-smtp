<?php
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Mnml_SMTP_Queue_Table extends WP_List_Table {
    public function __construct() {
        parent::__construct([
            'singular' => 'email',
            'plural'   => 'emails',
            'ajax'     => false,
        ]);
    }

    public function get_columns() {
        return [
            'cb'         => '<input type=checkbox />',
            'id'         => 'ID',
            'created_at' => 'Date',
            'to_email'   => 'To',
            'subject'    => 'Subject',
            'status'     => 'Status',
            'attempts'   => 'Attempts',
            'next_attempt'=> 'Next Attempt',
            'error'      => 'Error',
        ];
    }

    public function get_sortable_columns() {
        return [
            'id'         => ['id', true],
            'created_at' => ['created_at', false],
            'to_email'   => ['to_email', false],
            'subject'    => ['subject', false],
            'status'     => ['status', false],
            'attempts'   => ['attempts', false],
        ];
    }
    
    public function prepare_items() {
        global $wpdb;
        $table = $wpdb->prefix . 'mnml_smtp_queue';
        $per_page = 30;
        $current_page = $this->get_pagenum();
        $offset = ( $current_page - 1 ) * $per_page;

        // Sorting
        $orderby = ! empty( $_GET['orderby'] ) ? sanitize_text_field( $_GET['orderby'] ) : 'id';
        $order   = ! empty( $_GET['order'] ) && in_array( $_GET['order'], [ 'asc', 'desc' ] ) ? $_GET['order'] : 'desc';
        $sortable = array_keys( $this->get_sortable_columns() );
        if ( ! in_array( $orderby, $sortable, true ) ) {
            $orderby = 'id';
        }

        // Search (from search_box)
        $where = '';
        $search = ! empty( $_REQUEST['s'] ) ? trim( sanitize_text_field( $_REQUEST['s'] ) ) : '';
        if ( $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where = $wpdb->prepare( 'WHERE to_email LIKE %s OR subject LIKE %s', $like, $like );
        }

        // Column headers
        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
            'to_email'
        ];

        // Total count
        $total_items = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table $where" );

        // Fetch items
        $query = "SELECT * FROM $table $where ORDER BY $orderby $order LIMIT %d OFFSET %d";
        $this->items = $wpdb->get_results( $wpdb->prepare( $query, $per_page, $offset ) );

        $this->set_pagination_args( [
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_items / $per_page ),
        ] );
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'next_attempt':
                return $item->status === 'sent' ? '' : ($item->$column_name ? date('Y-m-d H:i:s', $item->$column_name) : '-');
            case 'attempts':
                return $item->$column_name ? intval($item->$column_name) : '';
            case 'error':
                return esc_html($item->$column_name) ? '<span class=msg>' . esc_html($item->$column_name) . '</span>' : '';
            default:
                return esc_html($item->$column_name);
        }
    }

    public function column_cb($item) {
        return sprintf('<input type=checkbox name=email_ids[] value=%s />', $item->id);
    }

    public function column_to_email($item) {
        $actions = [
            'resend' => sprintf('<a href=# data-action=resend data-id="%s">Resend</a>', $item->id),
            'view' => sprintf('<a href=# data-action=view data-id="%s">View</a>', $item->id),
        ];
        return sprintf('%1$s %2$s', str_replace( ',', '<br>', esc_html($item->to_email) ), $this->row_actions($actions));
    }

    public function column_actions($item) {
        return sprintf('<a href=# data-action=resend data-id="%s">resend</a> | <a href=# data-action=view data-id="%s">view</a>', $item->id, $item->id);
    }

    public function get_bulk_actions() {
        return [
            'resend_all_failed' => 'Resend All Failed',
            'resend_checked' => 'Resend Checked',
            'clear_sent' => 'Clear Sent',
            'clear_failed' => 'Clear Failed',
        ];
    }
}