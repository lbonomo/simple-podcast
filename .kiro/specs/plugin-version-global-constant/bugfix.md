# Bugfix Requirements Document

## Introduction

La versión del plugin se escribe a mano en varios lugares: el header `Version:` y la constante `LB_SP_VERSION` en `lb-simple-podcast.php`, `package.json`, `package-lock.json`, `readme.txt`, la constante `LB_SP_VERSION` de `tests/phpstan/bootstrap.php` (que vale `0.1.0`) y las aserciones con `0.7.0` fijo en `tests/phpunit/Unit/AuthorPreservationTest.php`. Nada comprueba que `LB_SP_VERSION` coincida con el header `Version:`, y el valor real del plugin en ejecución no es visible desde fuera de PHP.

El síntoma que motivó el pedido: el feed de producción (`https://lucasbonomo.com/feed/podcast/`) muestra `<generator>LB Simple Podcast 0.5.0</generator>` mientras el repositorio está en `0.7.0`. El generator del feed ya se arma con `LB_SP_VERSION` (`inc/Feed/PodcastFeedRenderer.php`), y los assets (`MetaBox.php`, `SettingsPage.php`, `PlayerInjector.php`) y el ETag del feed también. Por eso `0.5.0` indica un build desplegado desactualizado, y hoy no hay forma de detectar ese desajuste ni de impedir que la constante y el header se desincronicen en una futura publicación.

El objetivo es que `LB_SP_VERSION` sea la única fuente de verdad de la versión en el código PHP, que su consistencia con el resto de los archivos del release workflow de `AGENTS.md` esté verificada por tests, y que la versión en ejecución sea observable.

Fuera de alcance de este bugfix: la URL de los `<enclosure>` (`/?lb_sp_audio=ID&lb_sp_attachment=ID`), que devuelve el HTML de la home ante un GET en la raíz. Se debe tratar en una spec separada.

## Bug Analysis

### Current Behavior (Defect)

1.1 WHEN se actualiza la versión y el header `Version:` y `LB_SP_VERSION` en `lb-simple-podcast.php` difieren THEN the system no detecta la diferencia, porque ningún test compara la constante con el header (los tests actuales leen cada valor como texto contra el literal `0.7.0`).

1.2 WHEN se publica una nueva versión THEN the system obliga a editar a mano el literal de la versión en `tests/phpunit/Unit/AuthorPreservationTest.php` (nombres de test y aserciones con `0.7.0`), de modo que los tests fallan o quedan obsoletos en cada release aunque el código sea consistente.

1.3 WHEN se ejecuta el análisis estático con `tests/phpstan/bootstrap.php` THEN the system define `LB_SP_VERSION` como `0.1.0`, una segunda copia de la versión que no sigue al plugin.

1.4 WHEN el sitio ejecuta un build distinto del repositorio (por ejemplo el feed de producción muestra `0.5.0` y el repositorio está en `0.7.0`) THEN the system no ofrece ningún indicador de la versión en ejecución más allá del generator del feed, y no hay una prueba que confirme que ese generator proviene de la constante.

### Expected Behavior (Correct)

2.1 WHEN el header `Version:` y `LB_SP_VERSION` en `lb-simple-podcast.php` difieren THEN the system SHALL hacer fallar un test PHPUnit que compara el valor de la constante con el header `Version:` del archivo principal.

2.2 WHEN se publica una nueva versión THEN the system SHALL verificar la consistencia entre el header `Version:`, `LB_SP_VERSION`, `package.json`, `package-lock.json` (raíz y `packages[""]`) y `readme.txt` (`Stable tag`) comparándolos entre sí, sin un literal de versión escrito en los tests.

2.3 WHEN se ejecuta el análisis estático con `tests/phpstan/bootstrap.php` THEN the system SHALL obtener el valor de `LB_SP_VERSION` sin una copia fija de la versión que se desincronice (por ejemplo leyendo el header de `lb-simple-podcast.php`).

2.4 WHEN se genera el feed THEN the system SHALL emitir `<generator>` como `LB Simple Podcast ` seguido del valor de `LB_SP_VERSION`, y un test debe comprobarlo.

2.5 WHEN se busca una versión escrita a mano en `inc/`, `templates/` y `lb-simple-podcast.php` (fuera de la definición de la constante y del header) THEN the system SHALL no encontrar ninguna, y todo uso de la versión del plugin (generator, ETag del feed, versionado de scripts y estilos) SHALL usar `LB_SP_VERSION`. Un test debe comprobarlo.

### Unchanged Behavior (Regression Prevention)

3.1 WHEN se construye el feed con las mismas entradas THEN the system SHALL CONTINUE TO generar el mismo XML, con el generator `LB Simple Podcast <versión>`.

3.2 WHEN se calcula el ETag del feed THEN the system SHALL CONTINUE TO derivarlo de `FeedVersion::get()` y `LB_SP_VERSION`.

3.3 WHEN se encolan `classic.js`, `settings.js` y `player.css` THEN the system SHALL CONTINUE TO usar `LB_SP_VERSION` como parámetro de versión.

3.4 WHEN se encola `assets/build/index.js` THEN the system SHALL CONTINUE TO usar la versión de `assets/build/index.asset.php` generada por `@wordpress/scripts`.

3.5 WHEN `FeedVersion` se invalida o se lee THEN the system SHALL CONTINUE TO manejar su propio contador de caché, independiente de la versión del plugin. La opción `lb_sp_log_schema_version` y `RequestLog::VERSION` SHALL CONTINUE TO versionar el esquema de la tabla de logs.

3.6 WHEN se sigue el release workflow de `AGENTS.md` THEN the system SHALL CONTINUE TO usar los mismos lugares (header `Version:`, `LB_SP_VERSION`, `package.json`, `package-lock.json`, `readme.txt`) con el mismo valor.

3.7 WHEN el plugin se carga THEN the system SHALL CONTINUE TO definir `LB_SP_VERSION`, `LB_SP_FILE`, `LB_SP_DIR` y `LB_SP_URL` en `lb-simple-podcast.php`.

## Bug Condition

```pascal
FUNCTION isBugCondition(X)
  INPUT: X of type PluginSnapshot  // archivos de versión + código PHP + tests
  OUTPUT: boolean

  RETURN X.headerVersion != X.constVersion
      OR X.hasHardcodedPluginVersionOutsideSources     // en inc/, templates/ o bootstrap de phpstan
      OR NOT X.testComparesConstToHeader
      OR NOT X.testAssertsFeedGeneratorUsesConst
END FUNCTION
```

```pascal
// Property: Fix Checking
FOR ALL X WHERE isBugCondition(X) DO
  result ← checkVersionConsistency'(X)
  ASSERT result.constVersion = result.headerVersion
     AND result.constVersion = result.packageJsonVersion
     AND result.constVersion = result.packageLockVersions
     AND result.constVersion = result.readmeStableTag
     AND result.feedGenerator = "LB Simple Podcast " + result.constVersion
     AND no_hardcoded_plugin_version_in_php(result)
END FOR
```

```pascal
// Property: Preservation Checking
FOR ALL X WHERE NOT isBugCondition(X) DO
  ASSERT F(X) = F'(X)
END FOR
```
