<?php
/**
 * Sidebar links, grouped under section titles.
 *
 * @var array<string, list<array{0: string, 1: string, 2: string, 3?: bool}>> $groups
 *      section => [path, icon, label, exact match?]
 */
$here = uri_string();
foreach ($groups as $title => $links): ?>
    <div class="nav-section-title"><?= esc($title) ?></div>
    <?php foreach ($links as $link):
        [$path, $icon, $label] = $link;
        $active = ($link[3] ?? false) ? $here === $path : str_starts_with($here, $path);
    ?>
        <a href="<?= base_url($path) ?>" class="nav-link<?= $active ? ' active' : '' ?>"<?= $active ? ' aria-current="page"' : '' ?>>
            <i class="fa-solid <?= $icon ?>" aria-hidden="true"></i> <?= esc($label) ?>
        </a>
    <?php endforeach; ?>
<?php endforeach; ?>
