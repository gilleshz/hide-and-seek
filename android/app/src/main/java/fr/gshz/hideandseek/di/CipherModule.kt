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
    @ChatCipher
    fun provideChatCipher(): CredentialCipher = AndroidKeystoreCredentialCipher(ALIAS_CHAT)

    @Provides
    @Singleton
    @ApiKeyCipher
    fun provideApiKeyCipher(): CredentialCipher = AndroidKeystoreCredentialCipher(ALIAS_API_KEY)

    private const val ALIAS_CHAT = "hideandseek-chat"
    private const val ALIAS_API_KEY = "hideandseek-api-key"
}
