<?php

if (!function_exists('paginate_array')) {
    function paginate_array(array $items, int $perPage = 10, string $pageParam = 'page'): array
    {
        $totalItems = count($items);
        $perPage = max(1, $perPage);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));

        $currentPage = max(1, (int) ($_GET[$pageParam] ?? 1));
        $currentPage = min($currentPage, $totalPages);

        $offset = ($currentPage - 1) * $perPage;
        $pagedItems = array_slice($items, $offset, $perPage);

        return [
            'items' => $pagedItems,
            'current_page' => $currentPage,
            'per_page' => $perPage,
            'total_items' => $totalItems,
            'total_pages' => $totalPages,
            'offset' => $offset,
            'start_item' => $totalItems > 0 ? $offset + 1 : 0,
            'end_item' => $totalItems > 0 ? min($offset + $perPage, $totalItems) : 0,
        ];
    }
}

if (!function_exists('render_pagination')) {
    function render_pagination(
        array $pagination,
        string $pageParam = 'page',
        array $extraParams = [],
        ?string $basePath = null
    ): string {
        $totalPages = (int) ($pagination['total_pages'] ?? 1);
        if ($totalPages <= 1) {
            return '';
        }

        $currentPage = (int) ($pagination['current_page'] ?? 1);
        $totalItems = (int) ($pagination['total_items'] ?? 0);
        $startItem = (int) ($pagination['start_item'] ?? 0);
        $endItem = (int) ($pagination['end_item'] ?? 0);

        $params = $_GET;
        unset($params[$pageParam]);
        foreach ($extraParams as $key => $value) {
            if ($value === null || $value === '') {
                unset($params[$key]);
                continue;
            }
            $params[$key] = $value;
        }

        if ($basePath === null) {
            $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
            $basePath = $requestPath ?: basename($_SERVER['PHP_SELF'] ?? '');
        }

        $buildUrl = static function (int $page) use ($params, $pageParam, $basePath): string {
            $query = $params;
            if ($page > 1) {
                $query[$pageParam] = $page;
            }

            $url = $basePath;
            if (!empty($query)) {
                $url .= '?' . http_build_query($query);
            }

            return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        };

        $pagesToShow = [];
        for ($page = 1; $page <= $totalPages; $page++) {
            if (
                $page === 1 ||
                $page === $totalPages ||
                ($page >= $currentPage - 2 && $page <= $currentPage + 2)
            ) {
                $pagesToShow[] = $page;
            }
        }

        $html = '<nav class="mt-4" aria-label="Pagination">';
        $html .= '<div class="d-flex flex-column flex-md-row align-items-center justify-content-between gap-3">';
        $html .= '<div class="small text-secondary">Showing ' . $startItem . '-' . $endItem . ' of ' . $totalItems . '</div>';
        $html .= '<div class="d-flex flex-wrap justify-content-center gap-2">';

        if ($currentPage > 1) {
            $html .= '<a class="btn btn-sm btn-outline-secondary" href="' . $buildUrl($currentPage - 1) . '">Prev</a>';
        } else {
            $html .= '<span class="btn btn-sm btn-outline-secondary disabled">Prev</span>';
        }

        $previousPage = null;
        foreach ($pagesToShow as $page) {
            if ($previousPage !== null && $page - $previousPage > 1) {
                $html .= '<span class="btn btn-sm btn-outline-secondary disabled">...</span>';
            }

            if ($page === $currentPage) {
                $html .= '<span class="btn btn-sm btn-warning text-dark">' . $page . '</span>';
            } else {
                $html .= '<a class="btn btn-sm btn-outline-secondary" href="' . $buildUrl($page) . '">' . $page . '</a>';
            }

            $previousPage = $page;
        }

        if ($currentPage < $totalPages) {
            $html .= '<a class="btn btn-sm btn-outline-secondary" href="' . $buildUrl($currentPage + 1) . '">Next</a>';
        } else {
            $html .= '<span class="btn btn-sm btn-outline-secondary disabled">Next</span>';
        }

        $html .= '</div></div></nav>';

        return $html;
    }
}
