package fr.gshz.hideandseek.di

import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import fr.gshz.hideandseek.core.security.AndroidKeystoreCredentialCipher
import fr.gshz.hideandseek.core.security.CredentialCipher
import javax.inject.Singleton

@Module
@InstallIn(SingletonComponent::class)
object CipherModule {

    @Provides
    @Singleton
    fun providePasswordCipher(): CredentialCipher = AndroidKeystoreCredentialCipher(ALIAS_ACCOUNT_PASSWORD)

    @Provides
    @Singleton
    @ChatCipher
    fun provideChatCipher(): CredentialCipher = AndroidKeystoreCredentialCipher(ALIAS_CHAT)

    @Provides
    @Singleton
    @ApiKeyCipher
    fun provideApiKeyCipher(): CredentialCipher = AndroidKeystoreCredentialCipher(ALIAS_API_KEY)

    @Provides
    @Singleton
    @AccountPasswordCipher
    fun provideAccountPasswordCipher(): CredentialCipher = AndroidKeystoreCredentialCipher(ALIAS_ACCOUNT_PASSWORD)

    private const val ALIAS_ACCOUNT_PASSWORD = "hideandseek-account-password"
    private const val ALIAS_CHAT = "hideandseek-chat"
    private const val ALIAS_API_KEY = "hideandseek-api-key"
}
