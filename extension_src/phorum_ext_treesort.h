typedef struct phorum_treenode {
    long  id;          /* The id of the node. */
    long  parent_id;   /* The parent id of the node; 0 (zero) for root node. */
    long  indent_lvl;  /* The indent level of the node. */
    void *prev;        /* Previous node in linear mode. */
    void *next;        /* Next node in linear mode. */
    void *parent;      /* Parent node in tree mode. */
    void *child_first; /* First child in tree mode. */
    void *child_last;  /* Last child in tree mode. */
    void *sibling;     /* Next sibling child. */
    int   seen;        /* Keep track if the node was seen during tree walk. */
    HashPosition hp;   /* The Zend hash position. */
} phorum_treenode;

typedef struct phorum_tree {
    phorum_treenode *node_first;
    phorum_treenode *node_last;
} phorum_tree;
