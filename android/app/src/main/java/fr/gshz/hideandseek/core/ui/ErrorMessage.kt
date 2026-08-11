package fr.gshz.hideandseek.core.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ErrorOutline
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.dp
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.i18n.resolveError
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.core.ui.theme.AppTheme
import fr.gshz.hideandseek.core.ui.theme.Spacing

/** API Platform default detail strings that should fall through to a translated message. */
private val GENERIC_ERROR_DETAILS = setOf("Internal Server Error")

@Composable
fun ErrorType.message(): String = when (this) {
    ErrorType.Network -> stringResource(R.string.error_network)
    ErrorType.Unauthorized -> stringResource(R.string.error_unauthorized)
    ErrorType.NotFound -> stringResource(R.string.error_not_found)
    ErrorType.Validation -> stringResource(R.string.form_error_blank)
    ErrorType.Unknown -> stringResource(R.string.error_unknown)
}

/**
 * A consistent inline error banner: error-container surface, warning icon, and the
 * mapped message. Renders nothing when [error] is null.
 */
@Composable
fun ErrorText(
    error: ErrorType?,
    detail: String? = null,
    errorKey: String? = null,
    errorArgs: Map<String, String>? = null,
    modifier: Modifier = Modifier,
) {
    if (error == null) return
    val resolvedKey = errorKey?.let { resolveError(it, errorArgs) }
    Surface(
        modifier = modifier.fillMaxWidth(),
        color = MaterialTheme.colorScheme.errorContainer,
        contentColor = MaterialTheme.colorScheme.onErrorContainer,
        shape = MaterialTheme.shapes.small,
    ) {
        Row(
            modifier = Modifier.padding(horizontal = Spacing.md, vertical = Spacing.sm),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Spacing.sm),
        ) {
            Icon(
                imageVector = Icons.Filled.ErrorOutline,
                contentDescription = null,
                modifier = Modifier.size(20.dp),
            )
            val displayText = resolvedKey
                ?: detail?.takeUnless { it in GENERIC_ERROR_DETAILS }
                ?: error.message()
            Text(text = displayText, style = MaterialTheme.typography.bodyMedium)
        }
    }
}

@Preview
@Composable
private fun ErrorTextPreview() {
    AppTheme { ErrorText(ErrorType.Network, modifier = Modifier.padding(Spacing.md)) }
}
