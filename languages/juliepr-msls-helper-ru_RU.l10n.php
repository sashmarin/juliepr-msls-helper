<?php
/**
 * Russian translations. Rebuild from the paired PO file using wp i18n make-php.
 */

return array(
	'project-id-version' => 'Juliepr MSLS Helper 1.1.0',
	'language' => 'ru_RU',
	'mime-version' => '1.0',
	'content-type' => 'text/plain; charset=UTF-8',
	'content-transfer-encoding' => '8bit',
	'plural-forms' => 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
	'x-domain' => 'juliepr-msls-helper',
	'messages' => array(
		'Juliepr MSLS Helper' => 'Juliepr MSLS Helper',
		'Extends MSLS with shared media, source date/slug/author copying, imported link rewriting, 302 redirects to original content and daily orphaned option cleanup.' => 'Дополняет MSLS: использует общую медиатеку, копирует дату, slug и автора в новые переводы, заменяет ссылки в импортируемом тексте, перенаправляет при 404 на оригинал с кодом 302 и ежедневно очищает осиротевшие опции.',
		'You do not have permission to manage these settings.' => 'У вас нет прав для управления этими настройками.',
		'Enable global media library integration' => 'Включить интеграцию с глобальной медиатекой',
		'Use shared featured image IDs during MSLS Content Import. When disabled, MSLS uses its standard importer.' => 'Использовать общие ID изображений записей при импорте содержимого MSLS. При отключении MSLS использует свой стандартный импортёр.',
		'Copy the source date, slug and author' => 'Копировать дату, slug и автора исходной записи',
		'Apply to new translations created through Quick Create or Content Import.' => 'Применять к новым переводам, создаваемым через Quick Create или Content Import.',
		'Delete settings when uninstalling the plugin' => 'Удалить настройки при деинсталляции плагина',
		'Remove only the helper settings when the plugin is deleted. Posts and media are preserved.' => 'При удалении плагина удалять только его настройки. Записи и медиафайлы сохраняются.',
		'Settings saved.' => 'Настройки сохранены.',
		'Rewrite imported links to the translation site' => 'Заменять ссылки в импортируемом тексте на сайт перевода',
		'Replace the source site in text links during Quick Create and Content Import. Links to uploads and image URLs are preserved.' => 'Заменять исходный сайт в текстовых ссылках при Quick Create и Content Import. Ссылки на uploads и адреса изображений сохраняются.',
		'Redirect missing translations to the source site' => 'Перенаправлять при отсутствии перевода на исходный сайт',
		'On a 404 page, redirect with HTTP 302 only if a published post or page with the same path exists on the configured source site. Uploads are excluded.' => 'При ошибке 404 перенаправлять с кодом 302, только если на выбранном исходном сайте есть опубликованная запись или страница с тем же путём. Ссылки на uploads исключены.',
		'Source site ID for 404 redirects' => 'ID исходного сайта для редиректа при 404',
		'Default: 1. Choose an active site in this network. Redirects are never applied on the source site itself.' => 'По умолчанию: 1. Укажите активный сайт этой сети. На самом исходном сайте редирект не выполняется.',
		'Enter a positive source site ID. When redirects are enabled, the source must be an active site in this network.' => 'Укажите положительный ID исходного сайта. При включённом редиректе исходный сайт должен быть активным сайтом этой сети.',
		'Clean up orphaned MSLS options daily' => 'Ежедневно очищать осиротевшие опции MSLS',
		'Delete msls_ID and msls_term_ID options only when the corresponding post or term no longer exists on that site. All active sites in this network are processed in batches through WP-Cron.' => 'Удалять опции msls_ID и msls_term_ID, только если соответствующей записи или термина больше нет на этом сайте. Все активные сайты этой сети обрабатываются пакетами через WP-Cron.',
	),
);
