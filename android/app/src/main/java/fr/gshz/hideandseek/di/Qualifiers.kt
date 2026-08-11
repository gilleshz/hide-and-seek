package fr.gshz.hideandseek.di

import javax.inject.Qualifier

@Qualifier
@Retention(AnnotationRetention.RUNTIME)
annotation class ConnectionDataStore

@Qualifier
@Retention(AnnotationRetention.RUNTIME)
annotation class SessionDataStore

@Qualifier
@Retention(AnnotationRetention.RUNTIME)
annotation class DefaultDispatcher

@Qualifier
@Retention(AnnotationRetention.RUNTIME)
annotation class ChatCipher

@Qualifier
@Retention(AnnotationRetention.RUNTIME)
annotation class ApiKeyCipher

@Qualifier
@Retention(AnnotationRetention.RUNTIME)
annotation class AccountPasswordCipher
