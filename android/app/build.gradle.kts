plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

val siteUrl = (project.findProperty("siteUrl") as String?) ?: "https://rememvr.mycafe24.com"
// 빌드 번호(GitHub Actions 실행 번호). 앱을 새로 설치할 때 더 큰 번호여야 업데이트된다.
val buildNumber = (System.getenv("GITHUB_RUN_NUMBER") ?: "1").toInt()

android {
    namespace = "com.rememvr.app"
    compileSdk = 35

    defaultConfig {
        applicationId = "com.rememvr.app"
        minSdk = 24
        targetSdk = 35
        versionCode = buildNumber
        versionName = "0.1.$buildNumber"
        buildConfigField("String", "SITE_URL", "\"$siteUrl\"")
    }

    signingConfigs {
        // 테스트 배포용 고정 서명 키(저장소에 포함). 같은 키로 서명해야 새 APK 를 덮어 설치할 수 있다.
        // Play 스토어에 올릴 때는 별도 업로드 키를 만들어 GitHub Secrets 로 바꾼다.
        create("test") {
            storeFile = file("rememvr-test.keystore")
            storePassword = "rememvr-test"
            keyAlias = "rememvr"
            keyPassword = "rememvr-test"
        }
    }

    buildTypes {
        debug {
            signingConfig = signingConfigs.getByName("test")
        }
        release {
            isMinifyEnabled = false
            signingConfig = signingConfigs.getByName("test")
        }
    }

    buildFeatures {
        buildConfig = true
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
}

dependencies {
    implementation("androidx.webkit:webkit:1.12.1")
}
