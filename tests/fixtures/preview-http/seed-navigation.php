<?php

declare(strict_types=1);

use Imagine\Sites\Production\PrivatePreviewWorkspace;
use Imagine\Sites\Production\SiteBrief;

// Included only after bootstrap has verified the disposable SQL cluster and
// applied the real provisioner. It never creates roles, grants or schema.
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('SITES_PREVIEW_HTTP_CI') !== '1' ||
    !isset($previewConnections, $previewCompanies) || $previewCompanies['a'] !== 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa') {
    throw new RuntimeException('Synthetic navigation fixture unavailable');
}
foreach (['author', 'editor', 'editor_second'] as $profile) {
    if (!(($previewConnections[$profile] ?? null) instanceof PDO)) throw new RuntimeException('Synthetic navigation fixture unavailable');
}
$navigationWriter = new PrivatePreviewWorkspace($previewConnections['author']);
for ($number = 1; $number <= 55; ++$number) {
    $id = sprintf('50000000-0000-4000-8000-%012d', $number);
    $navigationWriter->createDemonstration($id, $previewCompanies['a'], new SiteBrief(
        'onepage', sprintf('Navegação CI %02d', $number), sprintf('Projeto sintético de navegação %02d', $number),
        'Conteúdo fictício para conferir todas as páginas da fila.', 'Conheça', ['Benefício fictício'],
    ));
}
$historyProject = '60000000-0000-4000-8000-000000000001';
$historyReviewers = [new PrivatePreviewWorkspace($previewConnections['editor']), new PrivatePreviewWorkspace($previewConnections['editor_second'])];
for ($revision = 1; $revision <= 60; ++$revision) {
    $brief = new SiteBrief('onepage', 'Histórico CI', 'Histórico sintético versão '.$revision,
        'Conteúdo fictício da versão '.$revision, 'Conheça', ['Benefício fictício']);
    $version = $revision === 1
        ? $navigationWriter->createDemonstration($historyProject, $previewCompanies['a'], $brief)
        : $navigationWriter->revise($historyProject, $previewCompanies['a'], $revision - 1, $brief);
    foreach ($historyReviewers as $reviewer) {
        $reviewer->review($historyProject, $previewCompanies['a'], $revision, $version['sha256'], 'approved_internal');
    }
}
echo "NAVIGATION FIXTURE OK: 55 queue projects, 60 immutable history versions, 120 independent synthetic reviews.\n";
