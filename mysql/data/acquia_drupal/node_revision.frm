�
    0  +         K 	      !          ��          //                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       �         �         �	    �PRIMARY�nid�uid�                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              InnoDB                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                h                                           6Stores information about each saved version of a node.                                                                                                                                                            
 � [  +@         P    �  � 
)                                          nid  vid  uid  title  log 	 
timestamp 
 status  comment  promote  sticky 

       !! 

      !(  	      !( I�       !   D  �!5 	
      !: 
      !p        !�  $     !i  (     ! �nid�vid�uid�title�log�timestamp�status�comment�promote�sticky� The node this version belongs to.The primary identifier for this version.The users.uid that created this version.The title of this version.The log entry explaining the changes in this version.A Unix timestamp indicating when this version was created.Boolean indicating whether the node (at the time of this revision) is published (visible to non-administrators).Whether comments are allowed on this node (at the time of this revision): 0 = no, 1 = closed (read only), 2 = open (read/write).Boolean indicating whether the node (at the time of this revision) should be displayed on the front page.Boolean indicating whether the node (at the time of this revision) should be displayed at the top of lists in which it appears.