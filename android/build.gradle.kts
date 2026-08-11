// Root build file. Plugins are declared here with `apply false` so their
// versions resolve once for the whole build; each module applies the ones it needs.
plugins {
    alias(libs.plugins.android.application) apply false
    alias(libs.plugins.kotlin.compose) apply false
    alias(libs.plugins.kotlin.serialization) apply false
    alias(libs.plugins.ksp) apply false
    alias(libs.plugins.hilt) apply false
    alias(libs.plugins.detekt)
}

detekt {
    buildUponDefaultConfig = true
    config.setFrom(files("$rootDir/config/detekt/detekt.yml"))
    // Analyze every module's Kotlin sources.
    source.setFrom(files(subprojects.map { "${it.projectDir}/src" }))
}
