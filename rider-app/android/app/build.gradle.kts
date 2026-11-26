import java.io.FileInputStream
import java.util.Properties

plugins {
    id("com.android.application")
    id("dev.flutter.flutter-gradle-plugin")
}

// android/local.properties (never committed) holds the Flutter SDK path and
// MAPS_API_KEY, the Google Maps SDK for Android key. The MAPS_API_KEY
// environment variable is used when the property is missing (CI builds).
val localProperties = Properties()
val localPropertiesFile = rootProject.file("local.properties")
if (localPropertiesFile.exists()) {
    localPropertiesFile.inputStream().use { localProperties.load(it) }
}
val mapsApiKey: String =
    localProperties.getProperty("MAPS_API_KEY") ?: System.getenv("MAPS_API_KEY") ?: ""

// android/key.properties (never committed) holds the release keystore details.
val keystoreProperties = Properties()
val keystoreFile = rootProject.file("key.properties")
val hasReleaseKeystore = keystoreFile.exists()
if (hasReleaseKeystore) {
    keystoreProperties.load(FileInputStream(keystoreFile))
}

android {
    namespace = "com.zonhack.redpharmabd.rider"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    defaultConfig {
        applicationId = "com.zonhack.redpharmabd.rider"
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
        manifestPlaceholders["MAPS_API_KEY"] = mapsApiKey
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    signingConfigs {
        if (hasReleaseKeystore) {
            create("release") {
                keyAlias = keystoreProperties.getProperty("keyAlias")
                keyPassword = keystoreProperties.getProperty("keyPassword")
                storeFile = file(keystoreProperties.getProperty("storeFile"))
                storePassword = keystoreProperties.getProperty("storePassword")
            }
        }
    }

    buildTypes {
        getByName("release") {
            // Without key.properties a release build is signed with the debug
            // key so `flutter run --release` still works on a test phone.
            signingConfig = if (hasReleaseKeystore) {
                signingConfigs.getByName("release")
            } else {
                signingConfigs.getByName("debug")
            }
            isMinifyEnabled = false
            isShrinkResources = false
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}
