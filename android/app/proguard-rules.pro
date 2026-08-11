# ProGuard/R8 rules for the release build.
# The libraries used here (Retrofit, OkHttp, Room, Hilt, kotlinx.serialization)
# ship their own consumer rules, so this file is intentionally near-empty.
#
# Add app-specific keep rules here if R8 strips something you need at runtime,
# e.g. models accessed only via reflection.

# kotlinx.serialization: keep the generated serializers for @Serializable classes.
-keepclassmembers class **$$serializer { *; }
-keepclasseswithmembers class * {
    kotlinx.serialization.KSerializer serializer(...);
}
