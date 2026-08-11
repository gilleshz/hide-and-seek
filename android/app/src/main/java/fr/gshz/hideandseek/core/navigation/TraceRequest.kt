package fr.gshz.hideandseek.core.navigation

import fr.gshz.hideandseek.domain.model.PhotoTarget

/**
 * A hider's request to answer a traced-streets photo question by drawing on the map. The game is
 * part of the request because several games can have a map on the back stack, and only the one this
 * question belongs to may answer it.
 */
data class TraceRequest(val gameUuid: String, val questionUuid: String, val photoTarget: PhotoTarget)
