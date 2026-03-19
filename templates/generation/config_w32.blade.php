// vim:ft=javascript
@php
/** @var \QtBuilder\Build\ExtensionBuildContext $ctx */
$includeRoots = $ctx->windowsCompileIncludeRoots();
$libraryRoot = $ctx->windowsLibraryRoot();
$libraries = $ctx->windowsModuleLibraryFiles();
$classSourceBasenames = $ctx->classSourceBasenames();
@endphp

ARG_ENABLE("{!! $ctx->extensionName !!}", "{!! strtoupper($ctx->extensionName) !!} support", "no");

if (PHP_{!! strtoupper($ctx->extensionName) !!} != "no") {
	var qt_include_roots = {!! json_encode($includeRoots, JSON_UNESCAPED_SLASHES) !!};
	var qt_library_root = {!! json_encode($libraryRoot, JSON_UNESCAPED_SLASHES) !!};
	var qt_libraries = {!! json_encode($libraries, JSON_UNESCAPED_SLASHES) !!};
	var qt_sources = {!! json_encode($classSourceBasenames, JSON_UNESCAPED_SLASHES) !!};
	var qt_enabled = qt_library_root !== null;

	ADD_FLAG("CFLAGS_{!! strtoupper($ctx->extensionName) !!}", "/std:c++17 /permissive- /EHsc /DZEND_ENABLE_STATIC_TSRMLS_CACHE=1");
	ADD_FLAG("CFLAGS_{!! strtoupper($ctx->extensionName) !!}", " /I \"" + configure_module_dirname + "\"");
	ADD_FLAG("CFLAGS_{!! strtoupper($ctx->extensionName) !!}", " /I \"" + configure_module_dirname + "\\classes\"");

	for (var i = 0; i < qt_include_roots.length; i++) {
		ADD_FLAG("CFLAGS_{!! strtoupper($ctx->extensionName) !!}", " /I \"" + qt_include_roots[i] + "\"");
	}

	if (qt_enabled) {
		for (var j = 0; j < qt_libraries.length; j++) {
			qt_enabled = CHECK_LIB(qt_libraries[j], "{!! $ctx->extensionName !!}", qt_library_root) && qt_enabled;
		}
	}

	if (qt_enabled) {
		EXTENSION("{!! $ctx->extensionName !!}", "{!! $ctx->moduleSourceFilename() !!}", PHP_{!! strtoupper($ctx->extensionName) !!}_SHARED);
		for (var k = 0; k < qt_sources.length; k++) {
			if (qt_sources[k].length > 0) {
				ADD_SOURCES(configure_module_dirname + "\\classes", qt_sources[k], "{!! $ctx->extensionName !!}");
			}
		}
		AC_DEFINE("HAVE_{!! strtoupper($ctx->extensionName) !!}", 1, "Define to 1 if the PHP extension '{!! $ctx->extensionName !!}' is available.");
		ADD_MAKEFILE_FRAGMENT();
	} else {
		WARNING("{!! $ctx->extensionName !!} not enabled; Qt libraries were not found under " + qt_library_root);
	}
}
