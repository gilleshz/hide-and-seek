import java.io.File

plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.compose)
    alias(libs.plugins.kotlin.serialization)
    alias(libs.plugins.ksp)
    alias(libs.plugins.hilt)
}

val keystorePath = System.getenv("KEYSTORE_PATH")
    ?: project.findProperty("KEYSTORE_PATH") as? String
val keystorePassword = System.getenv("KEYSTORE_PASSWORD")
    ?: project.findProperty("KEYSTORE_PASSWORD") as? String
val signingKeyAlias = System.getenv("KEY_ALIAS")
    ?: project.findProperty("KEY_ALIAS") as? String
val signingKeyPassword = System.getenv("KEY_PASSWORD")
    ?: project.findProperty("KEY_PASSWORD") as? String
val isSigningConfigured = keystorePath != null && file(keystorePath).exists()

android {
    namespace = "fr.gshz.hideandseek"
    compileSdk = 37

    androidResources {
        generateLocaleConfig = true
    }

    defaultConfig {
        applicationId = "fr.gshz.hideandseek"
        minSdk = 30
        targetSdk = 35
        versionCode = (project.findProperty("versionCode") as? String)?.toIntOrNull() ?: 1
        versionName = project.findProperty("versionName") as? String ?: "1.0"

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"

        ksp {
            arg("room.schemaLocation", "$projectDir/schemas")
        }
    }

    if (isSigningConfigured) {
        signingConfigs {
            create("release") {
                storeFile = file(keystorePath!!)
                storePassword = keystorePassword
                keyAlias = signingKeyAlias
                keyPassword = signingKeyPassword
            }
        }
    }

    buildTypes {
        debug {
            isPseudoLocalesEnabled = true
        }
        release {
            isMinifyEnabled = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro",
            )
            if (isSigningConfigured) {
                signingConfig = signingConfigs.getByName("release")
            }
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
        isCoreLibraryDesugaringEnabled = true
    }
    // AGP 9 built-in Kotlin: jvmTarget defaults to compileOptions.targetCompatibility (17).
    buildFeatures {
        compose = true
        buildConfig = true
    }

    testOptions {
        unitTests {
            isReturnDefaultValues = true
        }
    }

    lint {
        // i18n: hardcoded user-facing strings and missing translations must fail the build
        checkDependencies = true
        disable.add("GoogleAppIndexingWarning")
        error.add("HardcodedText")
        error.add("MissingTranslation")
    }
}

dependencies {
    // --- Android core + lifecycle ---
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.lifecycle.runtime.ktx)
    implementation(libs.androidx.lifecycle.runtime.compose)
    implementation(libs.androidx.lifecycle.viewmodel.compose)
    implementation(libs.androidx.activity.compose)

    // --- Jetpack Compose (BOM keeps all Compose artifact versions aligned) ---
    implementation(platform(libs.androidx.compose.bom))
    implementation(libs.androidx.compose.ui)
    implementation(libs.androidx.compose.ui.graphics)
    implementation(libs.androidx.compose.ui.tooling.preview)
    implementation(libs.androidx.compose.material3)
    implementation(libs.androidx.compose.material.icons.extended)
    implementation(libs.androidx.navigation.compose)
    debugImplementation(libs.androidx.compose.ui.tooling)

    // --- Dependency injection (Hilt) ---
    implementation(libs.hilt.android)
    implementation(libs.hilt.navigation.compose)
    ksp(libs.hilt.compiler)

    // --- Networking (Retrofit + OkHttp + kotlinx.serialization) ---
    implementation(libs.retrofit)
    implementation(libs.retrofit.kotlinx.serialization)
    implementation(libs.okhttp)
    implementation(libs.okhttp.logging)
    implementation(libs.okhttp.sse)
    implementation(libs.coil.compose)
    implementation(libs.coil.network.okhttp)
    implementation(libs.zxing.core)
    implementation(libs.kotlinx.serialization.json)

    // --- QR scanning (CameraX + zxing core; journeyapps is unmaintained) ---
    implementation(libs.androidx.camera.core)
    implementation(libs.androidx.camera.camera2)
    implementation(libs.androidx.camera.lifecycle)
    implementation(libs.androidx.camera.view)

    // --- Local persistence (Room) ---
    implementation(libs.room.runtime)
    ksp(libs.room.compiler)

    // --- Coroutines ---
    implementation(libs.kotlinx.coroutines.android)

    // --- Core library desugaring (java.time on minSdk 24) ---
    coreLibraryDesugaring(libs.desugar.jdk.libs)

    // --- Map rendering (MapLibre GL Native) ---
    implementation(libs.maplibre.android.sdk)

    // --- AppCompat (per-app language) ---
    implementation(libs.androidx.appcompat)

    // --- DataStore (connection config + session persistence) ---
    implementation(libs.androidx.datastore.preferences)

    // --- Unit tests (run on the JVM: src/test) ---
    testImplementation(platform(libs.junit.bom))
    testImplementation(libs.junit.jupiter)
    // Gradle 9 requires the platform launcher explicitly.
    testRuntimeOnly(libs.junit.platform.launcher)
    testImplementation(libs.mockk)
    testImplementation(libs.mockwebserver)
    testImplementation(libs.turbine)
    testImplementation(libs.kotlinx.coroutines.test)
    // Real org.json so GeoJSON parsing runs in JVM unit tests (the android.jar stub is a no-op).
    testImplementation(libs.json)

    // --- Instrumented / UI tests (run on a device or emulator: src/androidTest) ---
    androidTestImplementation(libs.junit)
    androidTestImplementation(libs.androidx.test.junit)
    androidTestImplementation(libs.androidx.espresso.core)
    androidTestImplementation(platform(libs.androidx.compose.bom))
    androidTestImplementation(libs.androidx.compose.ui.test.junit4)
    debugImplementation(libs.androidx.compose.ui.test.manifest)
}

// AGP 9 dropped testOptions.unitTests.all { useJUnitPlatform() }; configure the Gradle Test tasks directly.
tasks.withType<Test>().configureEach { useJUnitPlatform() }

// Fail-closed release signing: without credentials an unsigned APK must never ship silently.
// The check must not reference script-level state from inside doFirst: task actions are
// serialized by the configuration cache, and capturing `isSigningConfigured` (a script val)
// fails serialization. Resolve the value here at configuration time and capture only a String.
tasks.whenTaskAdded {
    if (name.startsWith("assembleRelease") || name.startsWith("bundleRelease")) {
        val keystoreFile = keystorePath?.let { project.file(it).absolutePath }
        doFirst {
            check(keystoreFile != null && File(keystoreFile).exists()) {
                "Release builds require KEYSTORE_PATH, KEYSTORE_PASSWORD, KEY_ALIAS and KEY_PASSWORD " +
                    "(environment or gradle.properties)."
            }
        }
    }
}
