package fr.gshz.hideandseek.feature.settings

import android.util.Log
import androidx.appcompat.app.AppCompatDelegate
import androidx.core.os.LocaleListCompat
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import fr.gshz.hideandseek.core.data.SettingsStore
import fr.gshz.hideandseek.core.model.AccountCredential
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.core.util.serverErrorKey
import fr.gshz.hideandseek.domain.repository.AccountRepository
import fr.gshz.hideandseek.domain.repository.ConnectionRepository
import java.io.IOException
import java.net.HttpURLConnection
import javax.inject.Inject
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import retrofit2.HttpException

@HiltViewModel
class SettingsViewModel @Inject constructor(
    private val settingsStore: SettingsStore,
    private val connectionRepository: ConnectionRepository,
    private val accountRepository: AccountRepository,
) : ViewModel() {

    private val _uiState = MutableStateFlow(SettingsUiState())
    val uiState: StateFlow<SettingsUiState> = _uiState.asStateFlow()

    init {
        viewModelScope.launch {
            settingsStore.locale.collect { savedTag ->
                _uiState.update { it.copy(selectedLocaleTag = savedTag) }
            }
        }
        viewModelScope.launch {
            settingsStore.themeMode.collect { savedTheme ->
                _uiState.update { it.copy(selectedTheme = savedTheme) }
            }
        }
        viewModelScope.launch {
            _uiState.update {
                it.copy(accountName = connectionRepository.accountCredential()?.name)
            }
        }
    }

    /**
     * Applies the selected locale immediately via AppCompat and persists the choice.
     *
     * The locale change is synchronous so the caller can trigger activity recreation
     * right after this returns. The DataStore write runs async in the background.
     */
    fun onLocaleSelected(tag: String) {
        if (tag.isEmpty()) {
            AppCompatDelegate.setApplicationLocales(LocaleListCompat.getEmptyLocaleList())
        } else {
            AppCompatDelegate.setApplicationLocales(LocaleListCompat.forLanguageTags(tag))
        }
        viewModelScope.launch {
            if (tag.isEmpty()) {
                settingsStore.clearLocale()
            } else {
                settingsStore.saveLocale(tag)
            }
            _uiState.update { it.copy(selectedLocaleTag = tag.ifEmpty { null }) }
        }
    }

    fun onThemeSelected(mode: String) {
        viewModelScope.launch {
            settingsStore.saveThemeMode(mode)
            _uiState.update { it.copy(selectedTheme = mode) }
        }
    }

    fun onCurrentPasswordChange(input: String) {
        _uiState.update {
            it.copy(
                currentPasswordInput = input.take(PASSWORD_MAX_LENGTH),
                passwordError = null,
                passwordErrorKey = null,
            )
        }
    }

    fun onNewPasswordChange(input: String) {
        _uiState.update {
            it.copy(
                newPasswordInput = input.take(PASSWORD_MAX_LENGTH),
                passwordError = null,
                passwordErrorKey = null,
            )
        }
    }

    fun changePassword() {
        val accountName = _uiState.value.accountName ?: return
        val current = _uiState.value.currentPasswordInput
        val new = _uiState.value.newPasswordInput
        if (current.length !in PASSWORD_MIN_LENGTH..PASSWORD_MAX_LENGTH ||
            new.length !in PASSWORD_MIN_LENGTH..PASSWORD_MAX_LENGTH
        ) {
            _uiState.update {
                it.copy(passwordError = ErrorType.Validation, passwordErrorKey = SHORT_PASSWORD_KEY)
            }
            return
        }
        performChange(accountName, current, new)
    }

    fun onPasswordChangedShown() {
        _uiState.update { it.copy(passwordChanged = false) }
    }

    @Suppress("TooGenericExceptionCaught")
    private fun performChange(accountName: String, currentPassword: String, newPassword: String) {
        viewModelScope.launch {
            _uiState.update {
                it.copy(isChangingPassword = true, passwordError = null, passwordErrorKey = null)
            }
            try {
                accountRepository.changePassword(accountName, currentPassword, newPassword)
                connectionRepository.saveAccount(AccountCredential(accountName, newPassword))
                _uiState.update {
                    it.copy(
                        isChangingPassword = false,
                        currentPasswordInput = "",
                        newPasswordInput = "",
                        passwordChanged = true,
                    )
                }
            } catch (e: IOException) {
                Log.w(TAG, "Change password failed", e)
                _uiState.update { it.copy(isChangingPassword = false, passwordError = ErrorType.Network) }
            } catch (e: HttpException) {
                Log.w(TAG, "Change password failed", e)
                val errorKey = if (e.code() == HttpURLConnection.HTTP_NOT_FOUND) {
                    SERVER_TOO_OLD_KEY
                } else {
                    e.serverErrorKey()
                }
                _uiState.update {
                    it.copy(
                        isChangingPassword = false,
                        passwordError = ErrorType.Unknown,
                        passwordErrorKey = errorKey,
                    )
                }
            } catch (e: Exception) {
                Log.w(TAG, "Change password failed (unexpected)", e)
                _uiState.update { it.copy(isChangingPassword = false, passwordError = ErrorType.Unknown) }
            }
        }
    }

    private companion object {
        const val TAG = "SettingsViewModel"
        const val PASSWORD_MIN_LENGTH = 4
        const val PASSWORD_MAX_LENGTH = 64
        const val SERVER_TOO_OLD_KEY = "connect.server_too_old"
        const val SHORT_PASSWORD_KEY = "settings.account_password_short"
    }
}
