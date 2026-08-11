package fr.gshz.hideandseek.feature.map

import androidx.annotation.StringRes
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.MutableState
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.ErrorText
import fr.gshz.hideandseek.core.ui.formatDurationWords
import fr.gshz.hideandseek.core.ui.theme.Spacing
import fr.gshz.hideandseek.domain.model.ScoreDeclaration

/**
 * Time-bonus cards only score if the hiders still hold them when caught, so they are totalled here
 * rather than tracked all round. The clock is already frozen by the caller, and the dialog stays up
 * until the server accepts the totals, so a refusal lands where the numbers still are.
 */
@Composable
internal fun EndRoundDialog(
    uiState: MapUiState,
    hidingSeconds: Long,
    onConfirm: (ScoreDeclaration) -> Unit,
    onDismiss: () -> Unit,
) {
    val minutesText = rememberSaveable { mutableStateOf("") }
    val percentText = rememberSaveable { mutableStateOf("") }
    val score = ScoreDeclaration(
        bonusMinutes = minutesText.value.toIntOrNull() ?: 0,
        bonusPercent = percentText.value.toIntOrNull() ?: 0,
        hidingSeconds = hidingSeconds,
    )
    val withinLimits = score.bonusMinutes <= MAX_BONUS_MINUTES && score.bonusPercent <= MAX_BONUS_PERCENT
    val submitted = remember { mutableStateOf(false) }
    LaunchedEffect(uiState.roundError) {
        if (uiState.roundError != null) submitted.value = false
    }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.end_round_title)) },
        text = {
            EndRoundFields(
                uiState = uiState,
                hidingSeconds = hidingSeconds,
                score = score,
                minutesText = minutesText,
                percentText = percentText,
            )
        },
        confirmButton = {
            TextButton(
                onClick = {
                    submitted.value = true
                    onConfirm(score)
                },
                enabled = !uiState.isEndingRound && !submitted.value && withinLimits,
            ) {
                Text(stringResource(R.string.end_round_confirm))
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) { Text(stringResource(android.R.string.cancel)) }
        },
    )
}

@Composable
private fun EndRoundFields(
    uiState: MapUiState,
    hidingSeconds: Long,
    score: ScoreDeclaration,
    minutesText: MutableState<String>,
    percentText: MutableState<String>,
) {
    Column(verticalArrangement = Arrangement.spacedBy(Spacing.md)) {
        ErrorText(uiState.roundError, errorKey = uiState.roundErrorKey, errorArgs = uiState.roundErrorArgs)
        ScoreRow(
            label = stringResource(R.string.end_round_hiding_time),
            value = formatDurationWords(hidingSeconds),
        )
        Text(
            text = stringResource(R.string.end_round_explainer),
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        BonusField(spec = MINUTES_FIELD, text = minutesText)
        BonusField(
            spec = PERCENT_FIELD,
            text = percentText,
            trailing = "+" + formatDurationWords(score.percentSecondsFor(hidingSeconds)),
        )
        HorizontalDivider()
        ScoreRow(
            label = stringResource(R.string.end_round_final_time),
            value = formatDurationWords(score.totalSecondsFor(hidingSeconds)),
            emphasise = true,
        )
    }
}

@Composable
private fun ScoreRow(label: String, value: String, emphasise: Boolean = false) {
    val style = if (emphasise) MaterialTheme.typography.titleMedium else MaterialTheme.typography.bodyLarge
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(text = label, style = style)
        Text(text = value, style = style)
    }
}

@Composable
private fun BonusField(spec: BonusFieldSpec, text: MutableState<String>, trailing: String? = null) {
    val exceedsLimit = (text.value.toIntOrNull() ?: 0) > spec.max
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.spacedBy(Spacing.sm),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        OutlinedTextField(
            value = text.value,
            onValueChange = { text.value = it.filter(Char::isDigit).take(MAX_DIGITS) },
            label = { Text(stringResource(spec.label)) },
            suffix = { Text(stringResource(spec.suffix)) },
            isError = exceedsLimit,
            supportingText = if (exceedsLimit) {
                { Text(stringResource(spec.limitMessage, spec.max)) }
            } else {
                null
            },
            singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
            modifier = Modifier.weight(1f),
        )
        if (trailing != null) {
            Text(
                text = trailing,
                style = MaterialTheme.typography.bodyMedium,
                textAlign = TextAlign.End,
            )
        }
    }
}

private data class BonusFieldSpec(
    @StringRes val label: Int,
    @StringRes val suffix: Int,
    @StringRes val limitMessage: Int,
    val max: Int,
)

private val MINUTES_FIELD = BonusFieldSpec(
    label = R.string.end_round_bonus_minutes,
    suffix = R.string.end_round_minutes_suffix,
    limitMessage = R.string.end_round_bonus_minutes_limit,
    max = MAX_BONUS_MINUTES,
)

private val PERCENT_FIELD = BonusFieldSpec(
    label = R.string.end_round_bonus_percent,
    suffix = R.string.end_round_percent_suffix,
    limitMessage = R.string.end_round_bonus_percent_limit,
    max = MAX_BONUS_PERCENT,
)

/** Both mirror the ranges RoundStopInput validates, so an over-entry never costs a round trip. */
private const val MAX_BONUS_MINUTES = 1440
private const val MAX_BONUS_PERCENT = 1000
private const val MAX_DIGITS = 4
