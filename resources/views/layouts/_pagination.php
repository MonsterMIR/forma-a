<?php
$p       = $pagination;
$current = $p['current_page'];
$last    = $p['last_page'];
$total   = $p['total'];
$from    = $p['from'];
$to      = $p['to'];

// Build query string preserving existing filters
$query = $_GET;
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <small class="text-muted">
        Showing <?= $from ?>–<?= $to ?> of <?= $total ?> records
    </small>
    <?php if ($last > 1): ?>
    <nav>
        <ul class="pagination pagination-sm mb-0">
            <?php if ($current > 1): ?>
            <li class="page-item">
                <a class="page-link" href="?<?= http_build_query(array_merge($query, ['page' => $current - 1])) ?>">
                    <i class="bi bi-chevron-left"></i>
                </a>
            </li>
            <?php endif; ?>

            <?php
            $start = max(1, $current - 2);
            $end   = min($last, $current + 2);
            if ($start > 1): ?>
            <li class="page-item">
                <a class="page-link" href="?<?= http_build_query(array_merge($query, ['page' => 1])) ?>">1</a>
            </li>
            <?php if ($start > 2): ?>
            <li class="page-item disabled"><span class="page-link">…</span></li>
            <?php endif; ?>
            <?php endif; ?>

            <?php for ($i = $start; $i <= $end; $i++): ?>
            <li class="page-item <?= $i === $current ? 'active' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($query, ['page' => $i])) ?>"><?= $i ?></a>
            </li>
            <?php endfor; ?>

            <?php if ($end < $last): ?>
            <?php if ($end < $last - 1): ?>
            <li class="page-item disabled"><span class="page-link">…</span></li>
            <?php endif; ?>
            <li class="page-item">
                <a class="page-link" href="?<?= http_build_query(array_merge($query, ['page' => $last])) ?>"><?= $last ?></a>
            </li>
            <?php endif; ?>

            <?php if ($current < $last): ?>
            <li class="page-item">
                <a class="page-link" href="?<?= http_build_query(array_merge($query, ['page' => $current + 1])) ?>">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </nav>
    <?php endif; ?>
</div>