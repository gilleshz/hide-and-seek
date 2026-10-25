package fr.gshz.hideandseek.feature.creategame

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.selection.toggleable
import androidx.compose.material3.Checkbox
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.CollapsibleSection
import fr.gshz.hideandseek.core.ui.theme.Spacing
import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.domain.model.GameSize

@Composable
internal fun CustomRulesSection(uiState: CreateGameUiState, actions: CreateGameActions) {
    if (uiState.size != GameSize.Small) return

    var expanded by rememberSaveable { mutableStateOf(false) }
    val activeCount = if (uiState.compactMap) 1 else 0
    CollapsibleSection(
        text = stringResource(R.string.custom_rules_title),
        summary = if (activeCount == 0) {
            stringResource(R.string.custom_rules_none)
        } else {
            pluralStringResource(R.plurals.custom_rules_active_count, activeCount, activeCount)
        },
        expanded = expanded,
        onToggle = { expanded = !expanded },
    ) {
        Text(
            text = stringResource(R.string.custom_rules_intro),
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        CustomRuleCheckbox(
            checked = uiState.compactMap,
            title = stringResource(R.string.custom_rule_smaller_thermometer),
            hint = stringResource(
                if (uiState.edition == Edition.Imperial) {
                    R.string.custom_rule_smaller_thermometer_hint_imperial
                } else {
                    R.string.custom_rule_smaller_thermometer_hint_metric
                },
            ),
            onCheckedChange = actions.onCompactMapChange,
        )
    }
}

@Composable
private fun CustomRuleCheckbox(
    checked: Boolean,
    title: String,
    hint: String,
    onCheckedChange: (Boolean) -> Unit,
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .toggleable(value = checked, role = Role.Checkbox, onValueChange = onCheckedChange)
            .padding(vertical = Spacing.xs),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Checkbox(checked = checked, onCheckedChange = null)
        Spacer(Modifier.width(Spacing.sm))
        Column {
            Text(text = title, style = MaterialTheme.typography.bodyMedium)
            Text(
                text = hint,
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}
