package fr.gshz.hideandseek.core.navigation

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.navigation.NavController
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import kotlinx.coroutines.flow.filter
import fr.gshz.hideandseek.core.scanner.QR_SCAN_RESULT_KEY
import fr.gshz.hideandseek.core.scanner.QrScannerScreen
import fr.gshz.hideandseek.core.util.ERROR_KEY_JOIN_PASSWORD_INVALID
import fr.gshz.hideandseek.core.util.ERROR_KEY_JOIN_PASSWORD_REQUIRED
import fr.gshz.hideandseek.feature.chat.ChatScreen
import fr.gshz.hideandseek.feature.connect.ConnectScreen
import fr.gshz.hideandseek.feature.connect.ConnectViewModel
import fr.gshz.hideandseek.feature.creategame.CreateGameScreen
import fr.gshz.hideandseek.feature.home.HomeScreen
import fr.gshz.hideandseek.feature.settings.SettingsScreen
import fr.gshz.hideandseek.feature.joingame.JoinGameScreen
import fr.gshz.hideandseek.feature.joingame.JoinGameViewModel
import fr.gshz.hideandseek.feature.lobby.LobbyScreen
import fr.gshz.hideandseek.feature.map.MapScreen

@Suppress("LongMethod")
@Composable
fun HideAndSeekNavHost(rootViewModel: RootViewModel = hiltViewModel()) {
    val startDestination by rootViewModel.startDestination.collectAsStateWithLifecycle()
    val destination = startDestination

    if (destination == null) {
        Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            CircularProgressIndicator()
        }
        return
    }

    val navController = rememberNavController()
    NavHost(navController = navController, startDestination = destination) {
        composable(
            route = HideAndSeekDestinations.CONNECT,
            arguments = listOf(
                navArgument(HideAndSeekDestinations.CONNECT_ERROR_KEY_ARG) {
                    type = NavType.StringType
                    nullable = true
                    defaultValue = null
                },
            ),
        ) { ConnectRoute(navController) }
        composable(HideAndSeekDestinations.SCANNER) { QrScannerRoute(navController) }
        composable(HideAndSeekDestinations.HOME) { HomeRoute(navController) }
        composable(HideAndSeekDestinations.SETTINGS) { SettingsScreen(onBackClick = { navController.popBackStack() }) }
        composable(HideAndSeekDestinations.CREATE_GAME) { CreateGameRoute(navController) }
        composable(
            route = HideAndSeekDestinations.JOIN_GAME,
            arguments = listOf(
                navArgument(HideAndSeekDestinations.JOIN_GAME_CODE_ARG) {
                    type = NavType.StringType
                    nullable = true
                    defaultValue = null
                },
                navArgument(HideAndSeekDestinations.JOIN_GAME_ERROR_KEY_ARG) {
                    type = NavType.StringType
                    nullable = true
                    defaultValue = null
                },
            ),
        ) { JoinGameRoute(navController) }
        composable(
            route = HideAndSeekDestinations.LOBBY,
            arguments = listOf(navArgument(HideAndSeekDestinations.LOBBY_ARG) { type = NavType.StringType }),
        ) { LobbyRoute(navController) }
        composable(
            route = HideAndSeekDestinations.MAP,
            arguments = listOf(navArgument(HideAndSeekDestinations.MAP_ARG) { type = NavType.StringType }),
        ) { MapRoute(navController) }
        composable(
            route = HideAndSeekDestinations.CHAT,
            arguments = listOf(navArgument(HideAndSeekDestinations.CHAT_ARG) { type = NavType.StringType }),
        ) { ChatRoute(navController) }
    }

    val pendingChatGameUuid by rootViewModel.pendingChatGameUuid.collectAsStateWithLifecycle()
    LaunchedEffect(pendingChatGameUuid) {
        pendingChatGameUuid?.let { gameUuid ->
            navController.navigate(HideAndSeekDestinations.chatRoute(gameUuid)) { launchSingleTop = true }
            rootViewModel.consumeChatRequest()
        }
    }

    SessionExpiredEffect(navController, rootViewModel)
}

@Composable
private fun SessionExpiredEffect(navController: NavController, rootViewModel: RootViewModel) {
    var handlingSessionExpired by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) {
        // Value-keyed effects restart on consume (Unit -> null) and cancel the rejoin.
        rootViewModel.sessionExpiredRequest
            .filter { it != null }
            .collect { errorKey ->
                if (handlingSessionExpired) return@collect
                handlingSessionExpired = true
                try {
                    when (val outcome = rootViewModel.handleSessionExpired(errorKey)) {
                        is SessionExpiredOutcome.Rejoined -> {
                            navController.navigate(HideAndSeekDestinations.lobbyRoute(outcome.gameUuid)) {
                                popUpTo(0) { inclusive = true }
                            }
                        }
                        is SessionExpiredOutcome.NeedsJoin -> {
                            val errorKey = outcome.errorKey
                            val needsConnect = errorKey == ERROR_KEY_JOIN_PASSWORD_REQUIRED ||
                                errorKey == ERROR_KEY_JOIN_PASSWORD_INVALID ||
                                errorKey == null && outcome.gameUuid == null
                            navController.navigate(
                                if (needsConnect) {
                                    HideAndSeekDestinations.connectRoute(errorKey)
                                } else {
                                    HideAndSeekDestinations.joinGameRoute(outcome.gameUuid, errorKey)
                                },
                            ) {
                                popUpTo(0) { inclusive = true }
                            }
                        }
                    }
                } finally {
                    handlingSessionExpired = false
                }
            }
    }
}

@Composable
private fun ConnectRoute(navController: NavController) {
    val connectViewModel: ConnectViewModel = hiltViewModel()
    ScanResultEffect(navController) { connectViewModel.onQrScanned(it) }
    ConnectScreen(
        onConnected = {
            navController.navigate(HideAndSeekDestinations.HOME) {
                popUpTo(HideAndSeekDestinations.CONNECT) { inclusive = true }
            }
        },
        onScanJoin = { code ->
            navController.navigate(HideAndSeekDestinations.HOME) {
                popUpTo(HideAndSeekDestinations.CONNECT) { inclusive = true }
            }
            navController.navigate(HideAndSeekDestinations.joinGameRoute(code))
        },
        onScanClick = { navController.navigate(HideAndSeekDestinations.SCANNER) },
        onOpenSettings = { navController.navigate(HideAndSeekDestinations.SETTINGS) },
    )
}

@Composable
private fun HomeRoute(navController: NavController) {
    val viewModel: RootViewModel = hiltViewModel()
    HomeScreen(
        onCreateGameClick = { navController.navigate(HideAndSeekDestinations.CREATE_GAME) },
        onJoinGameClick = { navController.navigate(HideAndSeekDestinations.joinGameRoute()) },
        onSettingsClick = { navController.navigate(HideAndSeekDestinations.SETTINGS) },
        onChangeServerClick = {
            viewModel.disconnect()
            navController.navigate(HideAndSeekDestinations.CONNECT) {
                popUpTo(0) { inclusive = true }
            }
        },
    )
}

@Composable
private fun CreateGameRoute(navController: NavController) {
    CreateGameScreen(
        onGameCreated = { gameUuid ->
            navController.navigate(HideAndSeekDestinations.lobbyRoute(gameUuid)) {
                popUpTo(HideAndSeekDestinations.HOME) { inclusive = true }
            }
        },
        onNeedAccount = { errorKey ->
            navController.navigate(HideAndSeekDestinations.connectRoute(errorKey)) {
                popUpTo(0) { inclusive = true }
            }
        },
    )
}

@Composable
private fun JoinGameRoute(navController: NavController) {
    val joinGameViewModel: JoinGameViewModel = hiltViewModel()
    ScanResultEffect(navController) { joinGameViewModel.onQrScanned(it) }
    JoinGameScreen(
        onJoined = { gameUuid ->
            navController.navigate(HideAndSeekDestinations.lobbyRoute(gameUuid)) {
                popUpTo(HideAndSeekDestinations.HOME) { inclusive = true }
            }
        },
        onNeedAccount = { errorKey ->
            navController.navigate(HideAndSeekDestinations.connectRoute(errorKey)) {
                popUpTo(0) { inclusive = true }
            }
        },
        onScanClick = { navController.navigate(HideAndSeekDestinations.SCANNER) },
    )
}

@Composable
private fun QrScannerRoute(navController: NavController) {
    QrScannerScreen(
        onQrScanned = { raw ->
            if (navController.currentDestination?.route != HideAndSeekDestinations.SCANNER) {
                return@QrScannerScreen
            }
            navController.previousBackStackEntry?.savedStateHandle?.set(QR_SCAN_RESULT_KEY, raw)
            navController.popBackStack()
        },
        onClose = { navController.popBackStack() },
    )
}

@Composable
private fun ScanResultEffect(navController: NavController, onResult: (String) -> Unit) {
    val backStackEntry = navController.currentBackStackEntry
    LaunchedEffect(backStackEntry) {
        val handle = backStackEntry?.savedStateHandle ?: return@LaunchedEffect
        handle.getStateFlow<String?>(QR_SCAN_RESULT_KEY, null).collect { raw ->
            raw?.let {
                onResult(it)
                handle[QR_SCAN_RESULT_KEY] = null
            }
        }
    }
}

@Composable
private fun LobbyRoute(navController: NavController) {
    LobbyScreen(
        onOpenMapClick = { gameUuid -> navController.navigate(HideAndSeekDestinations.mapRoute(gameUuid)) },
        onNavigateHome = {
            navController.navigate(HideAndSeekDestinations.HOME) {
                popUpTo(0) { inclusive = true }
            }
        },
        onOpenSettingsClick = { navController.navigate(HideAndSeekDestinations.SETTINGS) },
    )
}

@Composable
private fun MapRoute(navController: NavController) {
    MapScreen(
        onOpenChatClick = { gameUuid -> navController.navigate(HideAndSeekDestinations.chatRoute(gameUuid)) },
        onNavigateToLobby = { navController.popBackStack(HideAndSeekDestinations.LOBBY, false) },
    )
}

@Composable
private fun ChatRoute(navController: NavController) {
    ChatScreen(
        onBackClick = { navController.popBackStack() },
        onNavigateToMap = { gameUuid ->
            if (!navController.popBackStackToMapOf(gameUuid)) {
                navController.navigate(HideAndSeekDestinations.mapRoute(gameUuid))
            }
        },
    )
}

/**
 * Reusing the map still on the stack avoids rebuilding a second MapViewModel, but the route is a pattern:
 * popping to it blindly lands on whichever game's map is there, which would answer a question with another
 * game's drawing. Only the nearest matching entry is reused, and only when it is this game's.
 */
private fun NavController.popBackStackToMapOf(gameUuid: String): Boolean {
    val entry = try {
        getBackStackEntry(HideAndSeekDestinations.MAP)
    } catch (_: IllegalArgumentException) {
        null
    }
    return entry?.arguments?.getString(HideAndSeekDestinations.MAP_ARG) == gameUuid &&
        popBackStack(HideAndSeekDestinations.MAP, false)
}
