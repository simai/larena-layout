# Реализация

`FrameworkRecipeRequestFactory` переводит разрешённую проекцию существующей статьи и
настройку шапки в Recipe и snapshot входов. `FrameworkRecipeCompiler` проверяет
отпечаток Framework, запускает общий resolver и возвращает Document, HTML и receipt.
`FrameworkRecipeSnapshotPublisher` передаёт только успешный результат в
`FileCompiledPageSnapshotStore`. Store сохраняет неизменяемый снимок, атомарно
меняет активный указатель, отклоняет устаревшую ревизию и повторяет проверку прав.
