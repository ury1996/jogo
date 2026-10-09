<?php

/**
 * Onde está o projeto (a pasta com app/, config/, vendor/, biblioteca/…).
 * Padrão: a pasta acima desta raiz web (public_html/ dentro do projeto). Numa hospedagem
 * compartilhada, o instalador (instalar.php) troca este arquivo pelo caminho absoluto, porque a
 * pasta pública do subdomínio fica longe do projeto (que vai para fora da web).
 * Acessado pela internet, não mostra nada.
 */

declare(strict_types=1);

return dirname(__DIR__);
